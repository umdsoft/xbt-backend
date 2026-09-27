<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Human approval of outgoing messages (CLAUDE.md rule 1, PLAN-crm.md §6).
 *
 * Approval is UI-only: an MCP actor is refused here even if a tool were ever
 * wired to it. The approver sends back the hash of the text they saw; if the
 * text changed in between, approval fails instead of approving unseen text.
 */
final class ApprovalService
{
    public const BULK_MAX = 200;

    private const APPROVABLE_TIERS = ['A', 'B'];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly AuditLogger $audit,
    ) {}

    public function approve(Actor $actor, string $messageId, string $bodyHash): Message
    {
        $this->requireApprover($actor);

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId, $bodyHash): Message {
            $message = $this->lockMessage($messageId);
            $this->assertApprovable($message, $bodyHash);

            $message->forceFill([
                'status' => Message::APPROVED,
                'approved_by_user_id' => $actor->userId(),
                'approved_at' => now(),
            ])->save();

            $this->audit->log($actor, 'message.approved', 'message', $message->id, ['body_hash' => $message->body_hash]);

            return $message;
        });
    }

    public function reject(Actor $actor, string $messageId, string $reason): Message
    {
        $this->requireApprover($actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, 'Рад этиш сабаби шарт.', ['field' => 'reason']);
        }

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId, $reason): Message {
            $message = $this->lockMessage($messageId);
            if ($message->status !== Message::DRAFT) {
                throw new RuleViolation(RuleViolation::NOT_APPROVABLE, 'Фақат қоралама рад этилади.', ['status' => $message->status]);
            }

            $message->forceFill([
                'status' => Message::REJECTED,
                'rejected_by_user_id' => $actor->userId(),
                'rejected_at' => now(),
                'reject_reason' => $reason,
            ])->save();

            $this->audit->log($actor, 'message.rejected', 'message', $message->id, ['reason' => $reason]);

            return $message;
        });
    }

    /**
     * Each item is approved in its own savepoint: one bad item does not undo
     * the others, and every success is audited individually.
     *
     * @param  array<int, array{id: string, body_hash: string}>  $items
     * @return array{approved: array<int, string>, failed: array<int, array{id: string, reason: string, message: string}>}
     */
    public function bulkApprove(Actor $actor, array $items): array
    {
        $this->requireApprover($actor);
        $this->assertBulkSize(count($items));

        return $this->eachItem($items, fn (array $item): Message => $this->approve($actor, $item['id'], $item['body_hash']), 'approved');
    }

    /**
     * @param  array<int, string>  $ids
     * @return array{rejected: array<int, string>, failed: array<int, array{id: string, reason: string, message: string}>}
     */
    public function bulkReject(Actor $actor, array $ids, string $reason): array
    {
        $this->requireApprover($actor);
        $this->assertBulkSize(count($ids));

        $items = array_map(static fn (string $id): array => ['id' => $id], $ids);

        return $this->eachItem($items, fn (array $item): Message => $this->reject($actor, $item['id'], $reason), 'rejected');
    }

    /**
     * Edit a message's text. Approved/rejected messages return to draft (the
     * model drops the approval); sent or cancelled messages are final.
     */
    public function edit(Actor $actor, string $messageId, string $subject, string $body): Message
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId, $subject, $body): Message {
            $message = $this->lockMessage($messageId);
            $this->gate->company($actor, $message->contact->company_id);

            if (! in_array($message->status, [Message::DRAFT, Message::APPROVED, Message::REJECTED], true)) {
                throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Юборилган ёки бекор қилинган хат таҳрирланмайди.', ['status' => $message->status]);
            }

            $from = $message->status;
            $message->fill(['subject' => $subject, 'body' => $body]);
            if ($message->isDirty(['subject', 'body']) && $from === Message::REJECTED) {
                $message->status = Message::DRAFT;
            }
            $message->save();

            $this->audit->log($actor, 'message.edited', 'message', $message->id, [
                'from_status' => $from,
                'to_status' => $message->status,
                'body_hash' => $message->body_hash,
            ]);

            return $message;
        });
    }

    private function requireApprover(Actor $actor): void
    {
        if ($actor->via !== Stage::VIA_UI) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Хатни фақат инсон интерфейс орқали тасдиқлайди.');
        }

        $this->gate->require($actor, OutreachGate::APPROVE);
    }

    private function lockMessage(string $id): Message
    {
        $message = Str::isUuid($id)
            ? Message::query()->with('contact.company.country')->lockForUpdate()->find($id)
            : null;

        return $message ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Хат топилмади.');
    }

    private function assertApprovable(Message $message, string $bodyHash): void
    {
        $contact = $message->contact;
        /** @var Company $company */
        $company = $contact->company;

        $problem = match (true) {
            $message->status !== Message::DRAFT => ['not_draft', 'Фақат қоралама тасдиқланади.'],
            ! hash_equals($message->currentHash(), $bodyHash) => ['text_changed', 'Хат матни ўзгарган — қайта кўриб чиқинг.'],
            ! $contact->isActive() => ['contact_inactive', 'Контакт фаол эмас (боғланилмасин/обунадан чиққан/email нотўғри).'],
            $company->sanctions_status === 'hit' || $company->stage === Stage::BLOCKED_SANCTIONS => ['sanctions', 'Компания санкция рўйхатида.'],
            Stage::isClosed($company->stage) => ['lead_closed', 'Лид ёпилган.'],
            ! in_array($company->tier, self::APPROVABLE_TIERS, true) => ['low_tier', 'Фақат A ва B тоифадаги лидларга хат юборилади.'],
            $company->country === null || $company->country->excluded => ['country_excluded', 'Давлат истисно қилинган.'],
            default => null,
        };

        if ($problem !== null) {
            throw new RuleViolation(RuleViolation::NOT_APPROVABLE, $problem[1], ['check' => $problem[0]]);
        }
    }

    private function assertBulkSize(int $count): void
    {
        if ($count === 0 || $count > self::BULK_MAX) {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, 'Бир сўровда 1 тадан '.self::BULK_MAX.' тагача хат.', ['max' => self::BULK_MAX]);
        }
    }

    /**
     * @param  array<int, array<string, string>>  $items
     * @return array<string, array<int, mixed>>
     */
    private function eachItem(array $items, callable $fn, string $okKey): array
    {
        $ok = [];
        $failed = [];

        foreach ($items as $item) {
            try {
                $ok[] = $fn($item)->id;
            } catch (RuleViolation $e) {
                $failed[] = ['id' => $item['id'], 'reason' => $e->reason, 'message' => $e->getMessage()] + (isset($e->context['check']) ? ['check' => $e->context['check']] : []);
            }
        }

        return [$okKey => $ok, 'failed' => $failed];
    }
}
