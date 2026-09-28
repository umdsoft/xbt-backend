<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Letter series as drafts (SPEC §4.3 create_draft, PLAN-send.md §6).
 *
 * A series is 1–3 letters to one contact (days 0 / 4 / 10 after the first
 * letter actually leaves — the send scheduler computes the dates, nothing is
 * scheduled here). Everything this class writes is a DRAFT: it never approves
 * and never sends. Approval stays a human action in the UI.
 *
 * Revising goes through ApprovalService::edit, but only for draft/rejected
 * letters: revising an approved letter would silently drop the approval, so
 * that path is refused here (a person may still do it in the UI).
 */
final class SeriesService
{
    public const MAX_STEPS = 3;

    /** Day offsets of steps 1..3 relative to the first letter (SPEC §4.3). */
    public const STEP_OFFSET_DAYS = [1 => 0, 2 => 4, 3 => 10];

    public const SUBJECT_MAX = 300;

    public const BODY_MAX = 20000;

    /** Statuses that make a series "open": it may still be sent. */
    public const OPEN_STATUSES = [Message::DRAFT, Message::APPROVED, Message::SENDING];

    /** Lead stages in which a series can go out (SendGuard FIRST_LETTER/FOLLOW_UP + verified). */
    public const DRAFTABLE_STAGES = [Stage::VERIFIED, Stage::AWAITING_APPROVAL, Stage::SENT];

    private const TIERS = ['A', 'B'];

    private const REVISABLE = [Message::DRAFT, Message::REJECTED];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly StageMachine $stages,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid'],
            'language' => ['required', 'string', 'regex:/^[a-z]{2}$/'],
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.subject' => ['required', 'string', 'min:3', 'max:'.self::SUBJECT_MAX],
            'steps.*.body' => ['required', 'string', 'min:20', 'max:'.self::BODY_MAX],
        ];
    }

    /** @return array<string, mixed> */
    public static function reviseRules(): array
    {
        return [
            'message_id' => ['required', 'uuid'],
            'subject' => ['required_without:body', 'nullable', 'string', 'min:3', 'max:'.self::SUBJECT_MAX],
            'body' => ['required_without:subject', 'nullable', 'string', 'min:20', 'max:'.self::BODY_MAX],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  already shape-validated
     * @return array{series_id: string, messages: Collection<int, Message>, stage_moved: bool, company: Company}
     */
    public function create(Actor $actor, array $data): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $data): array {
            $contact = $this->accessibleContact($actor, (string) $data['contact_id'], $company);
            $language = (string) $data['language'];

            $this->assertDraftable($company, $contact, $language);
            $this->assertNoOpenSeries($contact);

            $seriesId = (string) Str::uuid();
            $messages = new Collection;
            foreach (array_values($data['steps']) as $i => $step) {
                $message = new Message([
                    'contact_id' => $contact->id,
                    'series_id' => $seriesId,
                    'sequence_step' => $i + 1,
                    'language' => $language,
                    'subject' => trim((string) $step['subject']),
                    'body' => (string) $step['body'],
                    'status' => Message::DRAFT,
                ]);
                $message->forceFill(['created_by' => $actor->userId()])->save();
                $messages->push($message);
            }

            $this->audit->log($actor, 'series.drafted', 'company', $company->id, [
                'series_id' => $seriesId,
                'contact_id' => $contact->id,
                'language' => $language,
                'message_ids' => $messages->pluck('id')->all(),
            ]);

            $moved = $company->stage === Stage::VERIFIED;
            if ($moved) {
                // Documented choice: the first series puts the lead into the approval queue.
                $this->stages->apply($actor, $company, Stage::AWAITING_APPROVAL, 'series_drafted');
            }

            return ['series_id' => $seriesId, 'messages' => $messages, 'stage_moved' => $moved, 'company' => $company];
        });
    }

    /**
     * Revise a draft or rejected letter. Rejected letters return to draft
     * (ApprovalService::edit). Approved, sending and final letters are refused.
     *
     * @param  array{subject?: ?string, body?: ?string}  $data
     */
    public function revise(Actor $actor, string $messageId, array $data): Message
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId, $data): Message {
            // Lock first so a person cannot approve between this check and the edit.
            $message = $this->lockMessage($actor, $messageId);

            if (! in_array($message->status, self::REVISABLE, true)) {
                throw new RuleViolation(
                    RuleViolation::NOT_EDITABLE,
                    $message->status === Message::APPROVED
                        ? 'Тасдиқланган хат бу канал орқали таҳрирланмайди (тасдиқ бекор бўлиб қолади).'
                        : 'Бу ҳолатдаги хат таҳрирланмайди.',
                    ['status' => $message->status, 'editable_statuses' => self::REVISABLE],
                );
            }

            return $this->approvals->edit(
                $actor,
                $message->id,
                isset($data['subject']) ? trim((string) $data['subject']) : (string) $message->subject,
                isset($data['body']) ? (string) $data['body'] : (string) $message->body,
            );
        });
    }

    /** Resolve a contact of a lead the actor may access; locks the lead. */
    private function accessibleContact(Actor $actor, string $contactId, ?Company &$company): Contact
    {
        $found = Str::isUuid($contactId) ? Contact::query()->find($contactId) : null;
        if ($found === null) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт топилмади.');
        }

        try {
            $company = $this->gate->company($actor, $found->company_id, lock: true);
        } catch (RuleViolation) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт топилмади.');
        }

        return Contact::query()->findOrFail($found->id);
    }

    private function assertDraftable(Company $company, Contact $contact, string $language): void
    {
        $allowed = $this->allowedLanguages($company, $contact);

        $problem = match (true) {
            ! $contact->isActive() => ['contact_inactive', 'Контакт фаол эмас (боғланилмасин/обунадан чиққан/email нотўғри).'],
            $contact->email === null || $contact->email === '' => ['no_email', 'Контактнинг email манзили йўқ.'],
            $this->isSuppressed((string) $contact->email) => ['suppressed', 'Бу манзил юборилмайдиганлар рўйхатида.'],
            $company->sanctions_status === 'hit' || $company->stage === Stage::BLOCKED_SANCTIONS => ['sanctions_hit', 'Компания санкция рўйхатида.'],
            Stage::isClosed($company->stage) => ['lead_closed', 'Лид ёпилган.'],
            ! in_array($company->stage, self::DRAFTABLE_STAGES, true) => ['stage_not_ready', 'Лид бу босқичда хат қабул қилмайди.'],
            ! in_array($company->tier, self::TIERS, true) => ['low_tier', 'Фақат A ва B тоифадаги лидларга хат юборилади.'],
            $company->country === null || $company->country->excluded => ['country_excluded', 'Давлат истисно қилинган.'],
            ! in_array($language, $allowed, true) => ['language_mismatch', 'Хат тили контакт ёки компания тилига мос эмас.'],
            default => null,
        };

        if ($problem !== null) {
            throw new RuleViolation(RuleViolation::NOT_DRAFTABLE, $problem[1], [
                'check' => $problem[0],
                'stage' => $company->stage,
                'allowed_stages' => self::DRAFTABLE_STAGES,
                'allowed_languages' => $allowed,
            ]);
        }
    }

    private function assertNoOpenSeries(Contact $contact): void
    {
        $open = Message::query()
            ->where('contact_id', $contact->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('created_at')
            ->first();

        if ($open !== null) {
            throw new RuleViolation(
                RuleViolation::ALREADY_OPEN,
                'Бу контакт учун очиқ хат серияси бор — янгисини эмас, мавжудини таҳрирланг.',
                ['series_id' => $open->series_id, 'message_id' => $open->id, 'status' => $open->status],
            );
        }
    }

    /**
     * Contact language, country language, company working languages, English.
     *
     * @return array<int, string>
     */
    private function allowedLanguages(Company $company, Contact $contact): array
    {
        return array_values(array_unique(array_filter([
            $contact->language,
            $company->country?->default_language,
            ...($company->languages ?? []),
            'en',
        ], static fn ($l): bool => is_string($l) && $l !== '')));
    }

    private function isSuppressed(string $email): bool
    {
        $email = strtolower($email);
        $domain = substr((string) strrchr($email, '@'), 1);

        return Suppression::query()
            ->where('email', $email)
            ->when($domain !== '', fn ($q) => $q->orWhere('domain', $domain))
            ->exists();
    }

    private function lockMessage(Actor $actor, string $id): Message
    {
        $companies = $this->gate->scope(Company::query(), $actor)->select('id');

        $message = Str::isUuid($id)
            ? Message::query()
                ->whereIn('contact_id', Contact::query()->whereIn('company_id', $companies)->select('id'))
                ->lockForUpdate()->find($id)
            : null;

        return $message ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Хат топилмади.');
    }
}
