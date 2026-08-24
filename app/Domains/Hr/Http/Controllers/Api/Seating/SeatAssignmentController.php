<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Exceptions\SeatConflictException;
use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAuditLog;
use App\Domains\Hr\Models\EventSeatAssignment;
use App\Domains\Hr\Models\Seat;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-SEAT biriktirish — har bir o'rindiqqa aniq mehmon/status.
 * "Bo'sh" = yozuv YO'Q. Bir tadbir + bir o'rindiq = bitta yozuv (double-assign bloklanadi).
 * Ko'rish = seating.view, yozish = seating.mark (EventPolicy view/update).
 */
class SeatAssignmentController extends HrController
{
    private const STATUSES = ['reserved', 'occupied', 'blocked'];

    /** Butun sxema + joriy biriktirishlar (seat_id bo'yicha OBYEKT). */
    public function map(Event $event, PlanBuilder $planBuilder): JsonResponse
    {
        $this->authorize('view', $event);

        $plan = $planBuilder->build($event->venue);

        $rows = EventSeatAssignment::where('event_id', $event->id)->get();
        $names = $this->resolveNames($rows->pluck('assigned_by')->filter()->unique()->values()->all());

        $assignments = [];
        foreach ($rows as $a) {
            $assignments[$a->seat_id] = $this->present($a, $names);
        }

        return response()->json([
            'plan' => $plan,
            'assignments' => (object) $assignments,
        ]);
    }

    /** Ko'p o'rindiqqa bulk biriktirish (faqat bo'sh o'rindiqlar; band → 409). */
    public function assign(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'seat_ids' => ['required', 'array', 'min:1'],
            'seat_ids.*' => ['uuid'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'event_group_id' => ['nullable', 'uuid'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_position' => ['nullable', 'string', 'max:255'],
            'guest_org' => ['nullable', 'string', 'max:255'],
            'hr_person_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        $seatIds = array_values(array_unique($data['seat_ids']));
        $status = $data['status'] ?? 'occupied';

        // IDOR: har bir o'rindiq shu obyektники (event.venue) bo'lishi shart.
        $valid = Seat::where('venue_id', $event->venue_id)->whereIn('id', $seatIds)->pluck('id')->all();
        if (count($valid) !== count($seatIds)) {
            throw ValidationException::withMessages([
                'seat_ids' => 'Ushbu obyektga tegishli bo\'lmagan o\'rindiq mavjud.',
            ]);
        }

        // Ismli/HR-havolali biriktirish faqat BITTA o'rindiqqa.
        $named = filled($data['guest_name'] ?? null) || filled($data['hr_person_id'] ?? null);
        if ($named && count($seatIds) > 1) {
            throw ValidationException::withMessages([
                'seat_ids' => 'Битта одам битта жойда ўтиради — исмли бириктириш фақат битта ўриндиққа.',
            ]);
        }

        $this->assertGroupBelongs($event, $data['event_group_id'] ?? null);

        try {
            $created = DB::connection('hr')->transaction(function () use ($event, $seatIds, $data, $status): Collection {
                // Konflikt (band) tekshiruvi — transaksiya ичида, throw → to'liq rollback.
                $existing = EventSeatAssignment::where('event_id', $event->id)
                    ->whereIn('seat_id', $seatIds)->get();
                if ($existing->isNotEmpty()) {
                    throw new SeatConflictException($existing);
                }

                $rows = collect();
                foreach ($seatIds as $sid) {
                    $rows->push(EventSeatAssignment::create([
                        'event_id' => $event->id,
                        'seat_id' => $sid,
                        'status' => $status,
                        'event_group_id' => $data['event_group_id'] ?? null,
                        'guest_name' => $data['guest_name'] ?? null,
                        'guest_position' => $data['guest_position'] ?? null,
                        'guest_org' => $data['guest_org'] ?? null,
                        'hr_person_id' => $data['hr_person_id'] ?? null,
                        'phone' => $data['phone'] ?? null,
                        'note' => $data['note'] ?? null,
                        'assigned_by' => $this->actor()->id,
                        'assigned_at' => now(),
                    ]));
                }

                EventAuditLog::create([
                    'event_id' => $event->id,
                    'user_id' => $this->actor()->id,
                    'action' => 'seats.assigned',
                    'payload_json' => ['count' => count($seatIds), 'status' => $status, 'group' => $data['event_group_id'] ?? null],
                    'created_at' => now(),
                ]);

                return $rows;
            });
        } catch (SeatConflictException $e) {
            return $this->conflict($e->occupied);
        } catch (QueryException $e) {
            // Race: unique(event_id, seat_id) buzilishi ham 409 shaklда qaytadi.
            if ($this->isUniqueViolation($e)) {
                return $this->conflict(
                    EventSeatAssignment::where('event_id', $event->id)->whereIn('seat_id', $seatIds)->get()
                );
            }
            throw $e;
        }

        $names = $this->resolveNames($created->pluck('assigned_by')->filter()->unique()->values()->all());
        $assignments = [];
        foreach ($created as $a) {
            $assignments[$a->seat_id] = $this->present($a, $names);
        }

        return response()->json(['assignments' => (object) $assignments]);
    }

    /** Biriktirishlarni bo'shatish (yozuvlar o'chiriladi). */
    public function release(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'seat_ids' => ['present', 'array'],
            'seat_ids.*' => ['uuid'],
        ]);

        $seatIds = array_values(array_unique($data['seat_ids']));

        $released = DB::connection('hr')->transaction(function () use ($event, $seatIds): int {
            if ($seatIds === []) {
                return 0;
            }

            return EventSeatAssignment::where('event_id', $event->id)
                ->whereIn('seat_id', $seatIds)->delete();
        });

        EventAuditLog::create([
            'event_id' => $event->id,
            'user_id' => $this->actor()->id,
            'action' => 'seats.released',
            'payload_json' => ['count' => $released],
            'created_at' => now(),
        ]);

        return response()->json(['released' => $released]);
    }

    /** Bitta o'rindiqni tahrirlash (mavjud bo'lmasa yaratiladi — upsert). */
    public function update(Request $request, Event $event, string $seat): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'event_group_id' => ['nullable', 'uuid'],
            'guest_name' => ['nullable', 'string', 'max:255'],
            'guest_position' => ['nullable', 'string', 'max:255'],
            'guest_org' => ['nullable', 'string', 'max:255'],
            'hr_person_id' => ['nullable', 'uuid'],
            'phone' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        // IDOR: o'rindiq shu obyектники bo'lishi shart.
        $seatModel = Seat::where('id', $seat)->where('venue_id', $event->venue_id)->firstOrFail();

        $this->assertGroupBelongs($event, $data['event_group_id'] ?? null);

        $assignment = DB::connection('hr')->transaction(function () use ($event, $seatModel, $data): EventSeatAssignment {
            $a = EventSeatAssignment::firstOrNew([
                'event_id' => $event->id,
                'seat_id' => $seatModel->id,
            ]);
            $isNew = ! $a->exists;

            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $a->status = $data['status'];
            } elseif ($isNew) {
                $a->status = 'occupied';
            }

            foreach (['event_group_id', 'guest_name', 'guest_position', 'guest_org', 'hr_person_id', 'phone', 'note'] as $f) {
                if (array_key_exists($f, $data)) {
                    $a->{$f} = $data[$f];
                }
            }

            if ($isNew) {
                $a->assigned_by = $this->actor()->id;
                $a->assigned_at = now();
            }

            $a->save();

            return $a;
        });

        EventAuditLog::create([
            'event_id' => $event->id,
            'user_id' => $this->actor()->id,
            'action' => 'seats.updated',
            'payload_json' => ['seat_id' => $seatModel->id],
            'created_at' => now(),
        ]);

        $names = $this->resolveNames(array_filter([$assignment->assigned_by]));

        return response()->json(['assignment' => $this->present($assignment, $names)]);
    }

    /** Bitta o'rindiq biriktirishi (yo'q bo'lsa null). */
    public function show(Event $event, string $seat): JsonResponse
    {
        $this->authorize('view', $event);

        $seatModel = Seat::where('id', $seat)->where('venue_id', $event->venue_id)->firstOrFail();

        $a = EventSeatAssignment::where('event_id', $event->id)
            ->where('seat_id', $seatModel->id)->first();

        if ($a === null) {
            return response()->json(['assignment' => null]);
        }

        $names = $this->resolveNames(array_filter([$a->assigned_by]));

        return response()->json(['assignment' => $this->present($a, $names)]);
    }

    /**
     * Bitta biriktirishni frontend kutayotgan shaklga aylantiradi.
     *
     * @param  array<string, string>  $names  assigned_by => display name
     * @return array<string, mixed>
     */
    private function present(EventSeatAssignment $a, array $names): array
    {
        return [
            'id' => $a->id,
            'seat_id' => $a->seat_id,
            'status' => $a->status,
            'event_group_id' => $a->event_group_id,
            'guest_name' => $a->guest_name,
            'guest_position' => $a->guest_position,
            'guest_org' => $a->guest_org,
            'hr_person_id' => $a->hr_person_id,
            'phone' => $a->phone,
            'note' => $a->note,
            'assigned_by_name' => $a->assigned_by !== null ? ($names[$a->assigned_by] ?? null) : null,
            'assigned_at' => $a->assigned_at?->toIso8601String(),
        ];
    }

    /** 409 — band o'rindiqlar ro'yxati bilan. */
    private function conflict(Collection $occupied): JsonResponse
    {
        return response()->json([
            'message' => 'Ba\'zi o\'rindiqlar allaqачон band. Tahrirlash uchun PATCH ishlating.',
            'occupied' => $occupied->map(fn (EventSeatAssignment $a) => [
                'seat_id' => $a->seat_id,
                'guest_name' => $a->guest_name,
                'status' => $a->status,
            ])->values(),
        ], 409);
    }

    /** assigned_by id'lar → display name (users.name). */
    private function resolveNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::connection('hr')->table('users')
            ->whereIn('id', array_values($ids))
            ->pluck('name', 'id')->all();
    }

    private function assertGroupBelongs(Event $event, ?string $groupId): void
    {
        if ($groupId !== null && ! $event->groups()->whereKey($groupId)->exists()) {
            throw ValidationException::withMessages([
                'event_group_id' => 'Guruh bu tadbirга tegishli emas.',
            ]);
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505'
            || (isset($e->errorInfo[0]) && $e->errorInfo[0] === '23505');
    }
}
