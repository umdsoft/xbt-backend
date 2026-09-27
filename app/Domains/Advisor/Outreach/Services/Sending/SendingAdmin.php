<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services\Sending;

use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Sender;
use App\Domains\Advisor\Outreach\Services\AuditLogger;
use App\Domains\Advisor\Outreach\Services\OutreachGate;
use App\Domains\Advisor\Outreach\Services\StageMachine;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Human controls over sending (PLAN-send.md §8–§9), UI only, viloyat
 * (`outreach.approve`): pause/resume, reset the breaker, manage the mailbox
 * pool, take a letter back out of the queue, decide ambiguous deliveries.
 */
final class SendingAdmin
{
    public function __construct(
        private readonly OutreachGate $gate,
        private readonly SendControl $control,
        private readonly SenderPool $pool,
        private readonly AuditLogger $audit,
        private readonly StageMachine $stages,
    ) {}

    public function pause(Actor $actor, string $reason): void
    {
        $this->authorize($actor);
        $this->control->stop($actor, SendControl::PAUSE, $reason);
    }

    public function resume(Actor $actor): void
    {
        $this->authorize($actor);
        $this->control->clear($actor, SendControl::PAUSE);
    }

    public function resetBreaker(Actor $actor): void
    {
        $this->authorize($actor);
        $this->control->clear($actor, SendControl::BREAKER);
    }

    /** @param  array<string, mixed>  $data */
    public function addSender(Actor $actor, array $data): Sender
    {
        $this->authorize($actor);

        return DB::connection('advisor')->transaction(function () use ($actor, $data): Sender {
            $sender = Sender::query()->create([
                'email' => strtolower((string) $data['email']),
                'mailer' => 'outreach',
                'display_name' => $data['display_name'],
                'warmup_started_on' => $data['warmup_started_on'] ?? now()->toDateString(),
                'daily_cap_max' => $data['daily_cap_max'] ?? 40,
            ]);
            $this->audit->log($actor, 'sender.added', 'sender', $sender->id, ['email' => $sender->email]);

            return $sender;
        });
    }

    /** @param  array<string, mixed>  $data  active, daily_cap_max, display_name, resume */
    public function updateSender(Actor $actor, string $id, array $data): Sender
    {
        $this->authorize($actor);
        $sender = (Str::isUuid($id) ? Sender::query()->find($id) : null)
            ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Қути топилмади.');

        $sender->fill(array_intersect_key($data, array_flip(['active', 'daily_cap_max', 'display_name'])));
        if (($data['resume'] ?? false) === true) {
            $sender->forceFill(['paused_at' => null, 'paused_reason' => null]);
        }
        $changes = array_keys($sender->getDirty());
        $sender->save();
        $this->audit->log($actor, 'sender.updated', 'sender', $sender->id, ['fields' => $changes]);

        return $sender;
    }

    /** Take an approved letter back before it is sent (it becomes a draft again). */
    public function unqueue(Actor $actor, string $messageId): Message
    {
        $this->authorize($actor);

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId): Message {
            $message = $this->lock($messageId);
            if ($message->status !== Message::APPROVED) {
                throw new RuleViolation(RuleViolation::NOT_APPROVABLE, 'Фақат навбатдаги (тасдиқланган, юборилмаган) хат навбатдан олинади.', ['status' => $message->status]);
            }
            $message->forceFill(['status' => Message::DRAFT, 'approved_by_user_id' => null, 'approved_at' => null, 'scheduled_for' => null])->save();
            $this->audit->log($actor, 'message.unqueued', 'message', $message->id);

            return $message;
        });
    }

    /**
     * A person decides a `send_unknown` letter after checking the mailbox's
     * Sent folder / recipient: `sent` (it did go out) or `failed` (it did not;
     * the letter will not be retried automatically — approve a copy if needed).
     */
    public function resolveUnknown(Actor $actor, string $messageId, string $outcome): Message
    {
        $this->authorize($actor);
        if (! in_array($outcome, [Message::SENT, Message::FAILED], true)) {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, 'Қарор: sent ёки failed.', ['field' => 'outcome']);
        }

        return DB::connection('advisor')->transaction(function () use ($actor, $messageId, $outcome): Message {
            $message = $this->lock($messageId);
            if ($message->status !== Message::SEND_UNKNOWN) {
                throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Бу хат ҳолати аниқ.', ['status' => $message->status]);
            }
            $message->forceFill(['status' => $outcome, 'sent_at' => $outcome === Message::SENT ? ($message->claimed_at ?? now()) : null])->save();

            $company = $message->contact->company()->lockForUpdate()->first();
            if ($outcome === Message::SENT && $company?->stage === Stage::AWAITING_APPROVAL) {
                $this->stages->apply(Actor::system(), $company, Stage::SENT, 'unknown_resolved_as_sent');
            }
            $this->audit->log($actor, 'message.unknown_resolved', 'message', $message->id, ['outcome' => $outcome]);

            return $message;
        });
    }

    /** @return array<string, mixed> */
    public function overview(Actor $actor, Carbon $now): array
    {
        $this->authorize($actor);
        $day = $this->pool->day($now);
        $since = $day->format('Y-m-d H:i:sP');

        $senders = Sender::query()->orderBy('email')->get()->map(fn (Sender $s): array => [
            'id' => $s->id,
            'email' => $s->email,
            'display_name' => $s->display_name,
            'active' => $s->active,
            'paused_at' => $s->paused_at?->toIso8601String(),
            'paused_reason' => $s->paused_reason,
            'warmup_started_on' => $s->warmup_started_on?->toDateString(),
            'daily_cap_max' => $s->daily_cap_max,
            'cap_today' => $s->isUsable() ? $s->capOn($day) : 0,
            'sent_today' => $this->pool->usedOn($s, $day),
        ])->all();

        $recent = Message::query()->whereNotNull('sent_at')->orderByDesc('sent_at')->limit(50)->pluck('status');

        return [
            'mode' => (string) config('outreach.send.mode', 'off'),
            'pause' => $this->control->get(SendControl::PAUSE),
            'breaker' => $this->control->get(SendControl::BREAKER),
            'today' => [
                'sent' => Message::query()->where('status', '!=', Message::FAILED)->whereNotNull('sent_at')->where('sent_at', '>=', $since)->count(),
                'capacity' => array_sum(array_column($senders, 'cap_today')),
            ],
            'queue' => [
                'approved' => Message::query()->where('status', Message::APPROVED)->count(),
                'due_now' => Message::query()->where('status', Message::APPROVED)
                    ->where(fn ($q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $now->format('Y-m-d H:i:sP')))->count(),
                'send_unknown' => Message::query()->where('status', Message::SEND_UNKNOWN)->count(),
                'failed_today' => Message::query()->where('status', Message::FAILED)->where('updated_at', '>=', $since)->count(),
            ],
            'bounce_rate_last_50' => $recent->isEmpty() ? null : round($recent->filter(fn ($s) => $s === Message::BOUNCED)->count() / $recent->count(), 4),
            'senders' => $senders,
        ];
    }

    private function authorize(Actor $actor): void
    {
        if ($actor->via !== Stage::VIA_UI) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Юборишни фақат инсон интерфейс орқали бошқаради.');
        }
        $this->gate->require($actor, OutreachGate::APPROVE);
    }

    private function lock(string $id): Message
    {
        return (Str::isUuid($id) ? Message::query()->with('contact')->lockForUpdate()->find($id) : null)
            ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Хат топилмади.');
    }
}
