<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAttendee;
use App\Domains\Hr\Models\EventAuditLog;
use App\Domains\Hr\Services\Seating\AttendeeDistributor;
use App\Domains\Hr\Services\Seating\CapacityCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nomlangan mehmonlar — ism taqsimlash, davomat (check-in), tuman kesimida hisobot.
 */
class AttendeeController extends HrController
{
    public function index(Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $attendees = EventAttendee::where('event_id', $event->id)
            ->orderBy('event_group_id')->orderBy('seat_number')
            ->get(['id', 'event_group_id', 'seat_row_id', 'seat_number', 'full_name', 'org', 'present', 'checked_in_at']);

        return response()->json(['attendees' => $attendees]);
    }

    /** Guruhга ismlarni bulk taqsimlash (o'rindiqlarга tartibli). */
    public function distribute(Request $request, Event $event, AttendeeDistributor $distributor): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'group_id' => ['required', 'uuid'],
            'names' => ['present', 'array'],
            'names.*' => ['nullable', 'string', 'max:255'],
        ]);

        $group = $event->groups()->findOrFail($data['group_id']);
        $count = $distributor->distribute($event, $group, $data['names']);

        EventAuditLog::create(['event_id' => $event->id, 'user_id' => $this->actor()->id, 'action' => 'attendees.distributed', 'payload_json' => ['group' => $group->id, 'count' => $count], 'created_at' => now()]);

        return response()->json([
            'message' => "{$count} та меҳмон тақсимланди.",
            'attendees' => EventAttendee::where('event_group_id', $group->id)->orderBy('seat_number')->get(),
        ]);
    }

    /** Davomat — kelgan/kelmagan (toggle). */
    public function checkin(Event $event, string $attendee): JsonResponse
    {
        $this->authorize('update', $event);

        $model = EventAttendee::where('event_id', $event->id)->findOrFail($attendee);
        $model->present = ! $model->present;
        $model->checked_in_at = $model->present ? now() : null;
        $model->save();

        return response()->json(['attendee' => $model->only(['id', 'present', 'checked_in_at'])]);
    }

    /** Hisobot: guruh (tuman) kesimida ajratilgan / nomlangan / kelgan. */
    public function attendance(Event $event, CapacityCalculator $calc): JsonResponse
    {
        $this->authorize('view', $event);

        $cap = $calc->forEvent($event);
        $named = EventAttendee::where('event_id', $event->id)
            ->selectRaw('event_group_id, count(*) named, count(*) filter (where present) present')
            ->groupBy('event_group_id')->get()->keyBy('event_group_id');

        $groups = $event->groups()->get()->map(function ($g) use ($cap, $named) {
            $assigned = collect($cap['groups'])->firstWhere('id', $g->id)['assigned'] ?? 0;
            $n = $named[$g->id] ?? null;

            return [
                'id' => $g->id,
                'name' => $g->name,
                'color' => $g->color,
                'district_id' => $g->district_id,
                'allocated' => $assigned,
                'named' => (int) ($n->named ?? 0),
                'present' => (int) ($n->present ?? 0),
            ];
        });

        return response()->json([
            'capacity' => $cap['capacity'],
            'assigned' => $cap['assigned'],
            'present_total' => (int) $groups->sum('present'),
            'named_total' => (int) $groups->sum('named'),
            'groups' => $groups->values(),
        ]);
    }
}
