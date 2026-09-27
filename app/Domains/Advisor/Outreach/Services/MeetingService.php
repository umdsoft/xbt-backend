<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Outreach\Services;

use App\Domains\Advisor\Outreach\Models\Company;
use App\Domains\Advisor\Outreach\Models\Contact;
use App\Domains\Advisor\Outreach\Models\Meeting;
use App\Domains\Advisor\Outreach\Support\Actor;
use App\Domains\Advisor\Outreach\Support\RuleViolation;
use App\Domains\Advisor\Outreach\Support\Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Online meetings with a lead (SPEC §4.4, minimal): proposals with time slots,
 * bookings with a start time and link, cancellations. No calendar
 * integration yet, and no delete.
 *
 * Meeting status transitions:
 *   new       -> proposed | booked
 *   proposed  -> proposed (update) | booked | cancelled
 *   booked    -> booked (reschedule) | cancelled | done (person in the UI only)
 *   done, cancelled are final.
 *
 * Lead stage follows through StageMachine: booking moves the lead to
 * meeting_booked (via replied when it was still at sent), cancelling the last
 * booked meeting returns it to replied, and `done` (UI) moves it to
 * meeting_done — a human decision (SPEC §5).
 */
final class MeetingService
{
    public const STATUSES = ['proposed', 'booked', 'done', 'cancelled'];

    public const OPEN = ['proposed', 'booked'];

    public const MAX_SLOTS = 5;

    /** Lead stages in which a meeting may be proposed or booked. */
    public const MEETING_STAGES = [Stage::SENT, Stage::REPLIED, Stage::MEETING_BOOKED];

    /** @var array<string, array<int, string>> */
    private const TRANSITIONS = [
        '' => ['proposed', 'booked'],
        'proposed' => ['proposed', 'booked', 'cancelled'],
        'booked' => ['booked', 'cancelled', 'done'],
    ];

    public function __construct(
        private readonly OutreachGate $gate,
        private readonly StageMachine $stages,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'meeting_id' => ['nullable', 'uuid'],
            'company_id' => ['required_without:meeting_id', 'nullable', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'proposed_slots' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_SLOTS],
            'proposed_slots.*' => ['date'],
            'start_at' => ['nullable', 'date'],
            'meeting_link' => ['nullable', 'url:https', 'max:500'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Create (no meeting_id) or update a meeting.
     *
     * @param  array<string, mixed>  $data  already shape-validated
     * @return array{meeting: Meeting, created: bool, effects: array<int, string>}
     */
    public function save(Actor $actor, array $data): array
    {
        $this->gate->require($actor, OutreachGate::MANAGE);

        return DB::connection('advisor')->transaction(function () use ($actor, $data): array {
            [$meeting, $company] = $this->resolve($actor, $data);
            $from = $meeting->exists ? (string) $meeting->status : '';
            $to = (string) $data['status'];

            $this->assertTransition($actor, $from, $to);
            if (in_array($to, self::OPEN, true)) {
                $this->assertLeadReady($company);
            }
            if ($from === '') {
                $this->assertNoOpenMeeting($company);
            }

            $meeting->fill(array_intersect_key($data, array_flip(['proposed_slots', 'start_at', 'meeting_link', 'notes'])));
            if (isset($data['contact_id'])) {
                $meeting->contact_id = $this->contactOf($company, (string) $data['contact_id'])->id;
            }
            $meeting->status = $to;
            $this->assertComplete($meeting);
            $meeting->save();

            $this->audit->log($actor, $from === '' ? 'meeting.created' : 'meeting.updated', 'meeting', $meeting->id, [
                'company_id' => $company->id,
                'from' => $from === '' ? null : $from,
                'to' => $to,
            ]);

            return ['meeting' => $meeting, 'created' => $from === '', 'effects' => $this->followStage($actor, $company, $meeting, $from, $to)];
        });
    }

    /** @return array{0: Meeting, 1: Company} */
    private function resolve(Actor $actor, array $data): array
    {
        if (! isset($data['meeting_id'])) {
            $company = $this->gate->company($actor, (string) ($data['company_id'] ?? ''), lock: true);

            return [new Meeting(['company_id' => $company->id]), $company];
        }

        $companies = $this->gate->scope(Company::query(), $actor)->select('id');
        $meeting = Str::isUuid((string) $data['meeting_id'])
            ? Meeting::query()->whereIn('company_id', $companies)->find($data['meeting_id'])
            : null;
        if ($meeting === null) {
            throw new RuleViolation(RuleViolation::NOT_FOUND, 'Учрашув топилмади.');
        }

        $company = $this->gate->company($actor, $meeting->company_id, lock: true);

        return [Meeting::query()->lockForUpdate()->findOrFail($meeting->id), $company];
    }

    private function assertTransition(Actor $actor, string $from, string $to): void
    {
        if (! array_key_exists($from, self::TRANSITIONS)) {
            throw new RuleViolation(RuleViolation::NOT_EDITABLE, 'Якунланган ёки бекор қилинган учрашув ўзгартирилмайди.', ['status' => $from]);
        }

        $allowed = self::TRANSITIONS[$from];
        if ($actor->via !== Stage::VIA_UI) {
            $allowed = array_values(array_diff($allowed, ['done']));   // SPEC §5: a person confirms the meeting took place
        }

        if (! in_array($to, $allowed, true)) {
            throw new RuleViolation(
                RuleViolation::TRANSITION_NOT_ALLOWED,
                "Учрашув «{$from}» → «{$to}» ўтиши бу канал орқали мумкин эмас.",
                ['from' => $from === '' ? null : $from, 'to' => $to, 'allowed' => $allowed],
            );
        }
    }

    private function assertLeadReady(Company $company): void
    {
        if (! in_array($company->stage, self::MEETING_STAGES, true)) {
            throw new RuleViolation(
                RuleViolation::TRANSITION_PRECONDITION,
                'Учрашув фақат жавоб берган лид билан белгиланади.',
                ['stage' => $company->stage, 'allowed_stages' => self::MEETING_STAGES],
            );
        }
    }

    private function assertNoOpenMeeting(Company $company): void
    {
        $open = $company->meetings()->whereIn('status', self::OPEN)->first();
        if ($open !== null) {
            throw new RuleViolation(RuleViolation::ALREADY_OPEN, 'Бу лид учун очиқ учрашув бор — уни янгиланг.', ['meeting_id' => $open->id, 'status' => $open->status]);
        }
    }

    private function assertComplete(Meeting $meeting): void
    {
        $missing = match (true) {
            $meeting->status === 'proposed' && empty($meeting->proposed_slots) => 'proposed_slots',
            $meeting->status === 'booked' && $meeting->start_at === null => 'start_at',
            default => null,
        };

        if ($missing !== null) {
            throw new RuleViolation(RuleViolation::INVALID_INPUT, "«{$missing}» майдони шарт.", ['field' => $missing]);
        }
    }

    private function contactOf(Company $company, string $contactId): Contact
    {
        $contact = Str::isUuid($contactId) ? Contact::query()->where('company_id', $company->id)->find($contactId) : null;

        return $contact ?? throw new RuleViolation(RuleViolation::NOT_FOUND, 'Контакт бу компанияга тегишли эмас.');
    }

    /** @return array<int, string> */
    private function followStage(Actor $actor, Company $company, Meeting $meeting, string $from, string $to): array
    {
        $moves = match (true) {
            $to === 'booked' && $from !== 'booked' => [Stage::SENT => Stage::REPLIED, Stage::REPLIED => Stage::MEETING_BOOKED],
            $to === 'cancelled' && $from === 'booked' && ! $this->hasOtherBooked($company, $meeting) => [Stage::MEETING_BOOKED => Stage::REPLIED],
            $to === 'done' => [Stage::MEETING_BOOKED => Stage::MEETING_DONE],
            default => [],
        };

        $effects = [];
        foreach ($moves as $fromStage => $toStage) {
            if ($company->stage === $fromStage) {
                $this->stages->apply($actor, $company, $toStage, 'meeting_'.$to);
                $effects[] = 'stage:'.$toStage;
            }
        }

        return $effects;
    }

    private function hasOtherBooked(Company $company, Meeting $meeting): bool
    {
        return $company->meetings()->where('status', 'booked')->whereKeyNot($meeting->id)->exists();
    }
}
