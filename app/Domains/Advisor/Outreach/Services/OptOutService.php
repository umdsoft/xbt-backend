<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Suppression;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\Normalize;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opt-out recorded by Claude (MCP `mark_unsubscribed`, reply classified as
 * `unsubscribe`) or by an advisor in the UI (SPEC §4.3, CLAUDE.md rule 5).
 *
 * Irreversible and idempotent: the contact is unsubscribed through
 * ContactService (cancels pending letters, closes the lead) and the address
 * lands on the suppression list, which outlives the contact record.
 */
final class OptOutService
{
    public function __construct(
        private readonly OutreachGate $gate,
        private readonly ContactService $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{contact: Contact, already: bool, suppressed: bool}
     */
    public function unsubscribe(Actor $actor, string $contactId, ?string $sourceMessageId = null): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $contactId, $sourceMessageId): array {
            $contact = Str::isUuid($contactId) ? Contact::query()->find($contactId) : null;
            if ($contact === null || ! $this->gate->canAccess($actor, $contact->company)) {
                throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт топилмади.');
            }

            $already = $contact->unsubscribed_at !== null;
            if (! $already) {
                $contact = $this->contacts->upsert($actor, [
                    'company_id' => $contact->company_id,
                    'contact_id' => $contact->id,
                    'unsubscribed' => true,
                ])['contact'];
            }

            $suppressed = $contact->email !== null
                && $this->suppress($actor, (string) $contact->email, 'unsubscribed', $sourceMessageId);

            return ['contact' => $contact, 'already' => $already, 'suppressed' => $suppressed];
        });
    }

    /**
     * Add an address to the suppression list. Returns false when it was
     * already there (ON CONFLICT DO NOTHING keeps the caller's transaction
     * valid even under a race).
     */
    public function suppress(Actor $actor, string $email, string $reason, ?string $sourceMessageId = null): bool
    {
        $email = Normalize::email($email);
        if ($email === '' || ! in_array($reason, Suppression::REASONS, true)) {
            return false;
        }

        $id = (string) Str::uuid();
        $inserted = Suppression::query()->insertOrIgnore([
            'id' => $id,
            'email' => $email,
            'reason' => $reason,
            'source_message_id' => $sourceMessageId !== null && Str::isUuid($sourceMessageId) ? $sourceMessageId : null,
            'created_at' => now()->format('Y-m-d H:i:sP'),
        ]);

        if ($inserted === 1) {
            $this->audit->log($actor, 'suppression.added', 'suppression', $id, ['email' => $email, 'reason' => $reason]);
        }

        return $inserted === 1;
    }
}
