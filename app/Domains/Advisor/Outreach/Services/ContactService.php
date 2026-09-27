<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Normalize;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Decision makers of a lead (upsert by normalized email).
 *
 *  - at most Contact::MAX_ACTIVE_PER_COMPANY active contacts; the company row
 *    is locked while counting, so two parallel MCP calls cannot both pass;
 *  - an email already used at another company is a duplicate;
 *  - do_not_contact and unsubscribe are one-way: input can set, never clear;
 *  - unsubscribe cancels the contact's pending messages and closes the lead.
 */
final class ContactService
{
    public const ROLE_TYPES = ['ceo', 'cto', 'coo', 'founder', 'vp_engineering', 'head_of_delivery', 'bd', 'hr', 'other'];

    public const EMAIL_STATUSES = ['verified', 'catch_all', 'invalid', 'unknown'];

    private const WRITABLE = ['full_name', 'title', 'role_type', 'email_status', 'linkedin_url', 'language'];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly CompanyService $companies,
        private readonly StageMachine $stages,
        private readonly DedupeService $dedupe,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'full_name' => ['required_without:contact_id', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'role_type' => ['nullable', Rule::in(self::ROLE_TYPES)],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'email_status' => ['nullable', Rule::in(self::EMAIL_STATUSES)],
            'linkedin_url' => ['nullable', 'url:https', 'max:500'],
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'do_not_contact' => ['nullable', 'boolean'],
            'unsubscribed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  already shape-validated
     * @return array{contact: Contact, created: bool}
     */
    public function upsert(Actor $actor, array $data): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        $email = isset($data['email']) && $data['email'] !== '' ? Normalize::email((string) $data['email']) : null;

        try {
            return DB::connection('advisor')->transaction(
                fn (): array => $this->write($actor, (string) $data['company_id'], $email, $data),
            );
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($actor, (string) $email);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{contact: Contact, created: bool}
     */
    private function write(Actor $actor, string $companyId, ?string $email, array $data): array
    {
        // Lock the company first: it serialises every contact write of this lead.
        $company = $this->gate->company($actor, $companyId, lock: true);

        $byEmail = $email === null ? null : Contact::query()->where('email', $email)->first();
        if ($byEmail !== null && $byEmail->company_id !== $company->id) {
            throw $this->duplicate($actor, $email);
        }

        $contact = $byEmail;
        if (isset($data['contact_id'])) {
            $contact = Contact::query()->where('company_id', $company->id)->find($data['contact_id'])
                ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт топилмади.');
            if ($byEmail !== null && $byEmail->id !== $contact->id) {
                throw $this->duplicate($actor, $email);
            }
            if ($email !== null) {
                $contact->email = $email;
            }
        }

        $created = $contact === null;
        $contact ??= new Contact(['company_id' => $company->id, 'email' => $email, 'email_status' => 'unknown']);
        if ($created) {
            $contact->forceFill(['created_by' => $actor->userId()]);
        }

        $wasActive = $contact->exists && $contact->isActive();
        $wasUnsubscribed = $contact->unsubscribed_at !== null;

        $this->assertOneWayFlags($contact, $data);
        $contact->fill(array_intersect_key($data, array_flip(self::WRITABLE)));
        if (($data['do_not_contact'] ?? false) === true) {
            $contact->do_not_contact = true;
        }
        if (($data['unsubscribed'] ?? false) === true && ! $wasUnsubscribed) {
            $contact->unsubscribed_at = now();
        }
        if ($contact->isDirty('email_status') && $contact->email_status === 'verified') {
            $contact->verified_at = now();
        }

        if ($contact->isActive() && ! $wasActive) {
            $this->assertCapacity($company, $contact);
        }

        $changes = $created ? [] : array_keys($contact->getDirty());
        $contact->save();

        $this->audit->log($actor, $created ? 'contact.created' : 'contact.updated', 'contact', $contact->id, $created
            ? ['company_id' => $company->id, 'email' => $email, 'role_type' => $contact->role_type]
            : ['fields' => $changes]);

        if (! $wasUnsubscribed && $contact->unsubscribed_at !== null) {
            $this->onUnsubscribe($actor, $company, $contact);
        }

        $this->companies->rescore($company);

        return ['contact' => $contact, 'created' => $created];
    }

    /** @param  array<string, mixed>  $data */
    private function assertOneWayFlags(Contact $contact, array $data): void
    {
        $clearsDnc = array_key_exists('do_not_contact', $data) && $data['do_not_contact'] === false && $contact->do_not_contact;
        $clearsUnsub = array_key_exists('unsubscribed', $data) && $data['unsubscribed'] === false && $contact->unsubscribed_at !== null;

        if ($clearsDnc || $clearsUnsub) {
            throw new RuleViolation(RuleViolation::IRREVERSIBLE, '«Боғланилмасин» ва обунадан чиқиш белгилари олиб ташланмайди.');
        }
    }

    private function assertCapacity(Company $company, Contact $contact): void
    {
        $active = Contact::query()->active()
            ->where('company_id', $company->id)
            ->when($contact->exists, fn ($q) => $q->whereKeyNot($contact->id))
            ->count();

        if ($active >= Contact::MAX_ACTIVE_PER_COMPANY) {
            throw new RuleViolation(
                RuleViolation::CONTACT_LIMIT,
                'Битта компанияда '.Contact::MAX_ACTIVE_PER_COMPANY.' тадан ортиқ фаол контакт бўлмайди.',
                ['limit' => Contact::MAX_ACTIVE_PER_COMPANY, 'active' => $active],
            );
        }
    }

    private function onUnsubscribe(Actor $actor, Company $company, Contact $contact): void
    {
        $cancelled = Message::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', [Message::DRAFT, Message::APPROVED])
            ->update(['status' => Message::CANCELLED]);

        $this->audit->log($actor, 'contact.unsubscribed', 'contact', $contact->id, ['cancelled_messages' => $cancelled]);

        if (! Stage::isClosed($company->stage)) {
            $this->stages->apply($actor, $company, Stage::CLOSED_UNSUBSCRIBED, 'contact_unsubscribed');
        }
    }

    private function duplicate(Actor $actor, string $email): RuleViolation
    {
        $contact = Contact::query()->with('company')->where('email', $email)->firstOrFail();
        $info = $this->dedupe->describe($actor, $contact->company, $contact);

        return new RuleViolation(
            RuleViolation::DUPLICATE,
            'Бу email бошқа компанияда бор (масъул: '.($info['owner_name'] ?? '—').').',
            $info,
        );
    }
}
