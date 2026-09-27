<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Message;
use App\Domains\Advisor\Outreach\Models\Reply;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Read side of the incoming-mail store plus classification (SPEC §4.3,
 * PLAN-send.md §7). Rows are written by the IMAP poller; this class only
 * reads them and writes classification/handling fields.
 *
 * Reply text is untrusted data (CLAUDE.md rule 2): nothing here interprets
 * it, and classification side effects depend only on the class chosen.
 *
 * Poller contract: rows of kind auto_reply/bounce/unsubscribe arrive already
 * classified (classified_at NULL) and their mechanical effects are done; kind
 * `reply` arrives with classification NULL = "needs classification", and the
 * poller has already moved the lead sent -> replied and cancelled the
 * contact's pending letters. Effects below are therefore idempotent safety
 * nets or the parts only a reader can decide.
 *
 * Side effects of a classification (only on a change, so re-sending the same
 * class is a no-op):
 *   interested        -> needs a person; lead sent -> replied if still at sent
 *   declined          -> pending letters cancelled, lead -> closed_declined
 *   unsubscribe       -> contact unsubscribed + suppressed (one-way)
 *   bounce            -> contact email_status invalid + suppressed (hard_bounce)
 *   later, auto_reply, other -> nothing
 * A first classification of a reply that reached a lead also logs an
 * incoming touch (history), except for auto_reply/bounce.
 * `declined`, `unsubscribe` and `bounce` are final: they cannot be
 * reclassified by anyone. The other classes may change freely; stage moves
 * already made are not undone.
 *
 * "Needs a person": classification interested and handled_at null. Only a
 * person marks a reply handled (UI).
 */
final class ReplyService
{
    public const FINAL = ['declined', 'unsubscribe', 'bounce'];

    public const SUMMARY_MAX = 2000;

    public const THREAD_LIMIT = 200;

    private const REPLIED_CLASSES = ['interested'];

    private const TOUCH_CLASSES = ['interested', 'later', 'declined', 'unsubscribe', 'other'];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly StageMachine $stages,
        private readonly ContactService $contacts,
        private readonly TouchService $touches,
        private readonly OptOutService $optOut,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function classifyRules(): array
    {
        return [
            'classification' => ['required', Rule::in(Reply::CLASSIFICATIONS)],
            'summary' => ['nullable', 'string', 'max:'.self::SUMMARY_MAX],
        ];
    }

    /**
     * Replies of leads the actor may access. A reply is matched to a lead by
     * company_id, or (when the poller could not set it) through the letter it
     * answers. Unmatched replies are visible to viloyat only.
     *
     * @return Builder<Reply>
     */
    public function scope(Actor $actor): Builder
    {
        if ($this->gate->actorSeesAll($actor)) {
            return Reply::query();
        }

        $companies = $this->gate->scope(Company::query(), $actor)->select('id');
        $letters = Message::query()
            ->whereIn('contact_id', Contact::query()->whereIn('company_id', $companies)->select('id'))
            ->select('id');

        return Reply::query()->where(fn (Builder $w) => $w
            ->whereIn('company_id', $companies)
            ->orWhere(fn (Builder $x) => $x->whereNull('company_id')->whereIn('message_id', $letters)));
    }

    public function find(Actor $actor, string $id, bool $lock = false): Reply
    {
        $query = $this->scope($actor);
        $reply = Str::isUuid($id) ? ($lock ? $query->lockForUpdate() : $query)->find($id) : null;

        return $reply ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Хат топилмади.');
    }

    /** @return array{company_id: ?string, contact_id: ?string} */
    public function links(Reply $reply): array
    {
        $letter = $reply->message_id !== null ? Message::query()->with('contact')->find($reply->message_id) : null;

        return [
            'company_id' => $reply->company_id ?? $letter?->contact?->company_id,
            'contact_id' => $reply->contact_id ?? $letter?->contact_id,
        ];
    }

    /**
     * @return array{reply: Reply, changed: bool, effects: array<int, string>}
     */
    public function classify(Actor $actor, string $id, string $classification, ?string $summary = null): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);
        if (! in_array($classification, Reply::CLASSIFICATIONS, true)) {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, 'Тасниф нотўғри.', ['allowed' => Reply::CLASSIFICATIONS]);
        }

        return DB::connection('advisor')->transaction(function () use ($actor, $id, $classification, $summary): array {
            $reply = $this->find($actor, $id, lock: true);
            $from = $reply->classification;

            if ($from === $classification) {
                return ['reply' => $reply, 'changed' => $this->updateSummary($actor, $reply, $summary), 'effects' => []];
            }

            if ($from !== null && in_array($from, self::FINAL, true)) {
                throw new RuleViolation(RuleViolation::IRREVERSIBLE, 'Бу таснифни ўзгартириб бўлмайди.', ['from' => $from, 'to' => $classification]);
            }

            $reply->forceFill([
                'classification' => $classification,
                'summary' => $summary ?? $reply->summary,
                'classified_at' => now(),
                'classified_via' => $actor->via,
            ])->save();

            $this->audit->log($actor, 'reply.classified', 'reply', $reply->id, ['from' => $from, 'to' => $classification]);

            return ['reply' => $reply, 'changed' => true, 'effects' => $this->applyEffects($actor, $reply, $classification, $from === null)];
        });
    }

    /** A person has dealt with the reply (UI only; idempotent). */
    public function markHandled(Actor $actor, string $id): Reply
    {
        if ($actor->via !== Stage::VIA_UI) {
            throw new RuleViolation(RuleViolation::FORBIDDEN, 'Жавобни фақат инсон кўриб чиққан деб белгилайди.');
        }
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $id): Reply {
            $reply = $this->find($actor, $id, lock: true);
            if ($reply->handled_at === null) {
                $reply->forceFill(['handled_at' => now()])->save();
                $this->audit->log($actor, 'reply.handled', 'reply', $reply->id);
            }

            return $reply;
        });
    }

    /**
     * The conversation with a lead: letters that left, replies and touches,
     * oldest first (the newest THREAD_LIMIT items), plus meetings.
     *
     * @return array{company: Company, contacts: Collection<int, Contact>, items: array<int, array{type: string, at: mixed, model: mixed}>, meetings: Collection<int, mixed>, truncated: bool}
     */
    public function thread(Actor $actor, string $companyId, ?string $contactId = null): array
    {
        $this->gate->require($actor, OutreachGate::VIEW);
        $company = $this->gate->company($actor, $companyId);
        $contacts = $company->contacts()->orderBy('created_at')->get();

        if ($contactId !== null && ! $contacts->contains('id', $contactId)) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт топилмади.');
        }
        $contactIds = $contactId !== null ? [$contactId] : $contacts->pluck('id')->all();

        $allLetters = Message::query()->whereIn('contact_id', $contactIds)->get();
        $letters = $allLetters->filter(fn (Message $m): bool => $m->sent_at !== null
            || in_array($m->status, [Message::SENT, Message::BOUNCED, Message::REPLIED, Message::SEND_UNKNOWN], true));

        $replies = Reply::query()->where(function (Builder $q) use ($company, $contactId, $contactIds, $allLetters): void {
            $q->whereIn('message_id', $allLetters->pluck('id'))->orWhereIn('contact_id', $contactIds);
            if ($contactId === null) {
                $q->orWhere('company_id', $company->id);
            }
        })->get();

        $touches = $company->touchHistory()
            ->when($contactId, fn ($q, string $id) => $q->where('contact_id', $id))
            ->get();

        $items = collect()
            ->concat($letters->map(fn (Message $m): array => ['type' => 'letter', 'at' => $m->sent_at ?? $m->updated_at, 'model' => $m]))
            ->concat($replies->map(fn (Reply $r): array => ['type' => 'reply', 'at' => $r->received_at, 'model' => $r]))
            ->concat($touches->map(fn ($t): array => ['type' => 'touch', 'at' => $t->occurred_at, 'model' => $t]))
            ->sortBy(fn (array $i) => $i['at']?->getTimestamp() ?? 0)
            ->values();

        return [
            'company' => $company,
            'contacts' => $contacts,
            'items' => $items->slice(-self::THREAD_LIMIT)->values()->all(),
            'meetings' => $company->meetings()->orderBy('created_at')->get(),
            'truncated' => $items->count() > self::THREAD_LIMIT,
        ];
    }

    private function updateSummary(Actor $actor, Reply $reply, ?string $summary): bool
    {
        if ($summary === null || $summary === $reply->summary) {
            return false;
        }

        $reply->forceFill(['summary' => $summary])->save();
        $this->audit->log($actor, 'reply.summary_updated', 'reply', $reply->id);

        return true;
    }

    /** @return array<int, string> */
    private function applyEffects(Actor $actor, Reply $reply, string $class, bool $firstTime): array
    {
        $links = $this->links($reply);

        if ($links['company_id'] === null) {
            $suppressed = $class === 'unsubscribe' && $this->optOut->suppress($actor, (string) $reply->from_email, 'unsubscribed', $reply->message_id);

            return $suppressed ? ['unmatched', 'suppressed'] : ['unmatched'];
        }

        $company = $this->gate->company($actor, $links['company_id'], lock: true);
        $effects = match ($class) {
            'interested' => $this->markReplied($actor, $company, $class),
            'declined' => $this->decline($actor, $company),
            'unsubscribe' => $this->unsubscribe($actor, $reply, $links['contact_id']),
            'bounce' => $this->bounce($actor, $company, $reply, $links['contact_id']),
            default => [],
        };

        if ($firstTime && in_array($class, self::TOUCH_CLASSES, true)) {
            $this->touches->log($actor, [
                'company_id' => $company->id,
                'contact_id' => $links['contact_id'],
                'channel' => 'email',
                'direction' => 'in',
                'summary' => mb_substr('Кирувчи хат ('.$class.')'.($reply->summary ? ': '.$reply->summary : ''), 0, 4000),
                'occurred_at' => ($reply->received_at ?? now())->toIso8601String(),
            ]);
            $effects[] = 'touch_logged';
        }

        return $effects;
    }

    /** @return array<int, string> */
    private function markReplied(Actor $actor, Company $company, string $class): array
    {
        if ($company->stage !== Stage::SENT || ! in_array($class, self::REPLIED_CLASSES, true)) {
            return [];
        }
        $this->stages->apply($actor, $company, Stage::REPLIED, 'reply_'.$class);

        return ['stage:'.Stage::REPLIED];
    }

    /** @return array<int, string> */
    private function decline(Actor $actor, Company $company): array
    {
        $effects = [];
        $cancelled = Message::query()
            ->whereIn('contact_id', $company->contacts()->select('id'))
            ->whereIn('status', [Message::DRAFT, Message::APPROVED])
            ->update(['status' => Message::CANCELLED]);
        if ($cancelled > 0) {
            $this->audit->log($actor, 'messages.cancelled', 'company', $company->id, ['count' => $cancelled, 'reason' => 'reply_declined']);
            $effects[] = 'cancelled_messages:'.$cancelled;
        }

        if (Stage::allows($company->stage, Stage::CLOSED_DECLINED, $actor->via)) {
            $this->stages->apply($actor, $company, Stage::CLOSED_DECLINED, 'reply_declined');
            $effects[] = 'stage:'.Stage::CLOSED_DECLINED;
        } else {
            $effects[] = 'stage_unchanged';
        }

        return $effects;
    }

    /** @return array<int, string> */
    private function unsubscribe(Actor $actor, Reply $reply, ?string $contactId): array
    {
        $effects = [];
        if ($contactId !== null) {
            $result = $this->optOut->unsubscribe($actor, $contactId, $reply->message_id);
            $effects[] = $result['already'] ? 'contact_already_unsubscribed' : 'contact_unsubscribed';
            if ($result['suppressed']) {
                $effects[] = 'suppressed';
            }
        }
        if ($this->optOut->suppress($actor, (string) $reply->from_email, 'unsubscribed', $reply->message_id)) {
            $effects[] = 'suppressed_sender';
        }

        return $effects;
    }

    /** @return array<int, string> */
    private function bounce(Actor $actor, Company $company, Reply $reply, ?string $contactId): array
    {
        $contact = $contactId !== null ? Contact::query()->where('company_id', $company->id)->find($contactId) : null;
        if ($contact === null) {
            return [];
        }

        $effects = [];
        if ($contact->email_status !== 'invalid') {
            $this->contacts->upsert($actor, ['company_id' => $company->id, 'contact_id' => $contact->id, 'email_status' => 'invalid']);
            $effects[] = 'contact_invalid';
        }
        if ($contact->email !== null && $this->optOut->suppress($actor, (string) $contact->email, 'hard_bounce', $reply->message_id)) {
            $effects[] = 'suppressed';
        }

        return $effects;
    }
}
