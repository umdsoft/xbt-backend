<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Controllers\Api\Seating;

use App\Domains\Hr\Http\Controllers\Api\HrController;
use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAuditLog;
use App\Domains\Hr\Models\EventGroup;
use App\Domains\Hr\Models\EventSnapshot;
use App\Domains\Hr\Services\Seating\AllocationWriter;
use App\Domains\Hr\Services\Seating\CapacityCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tadbirlar — TENANT (hokimlik_id) bo'yicha avtomatik scope. Guruh va belgilash
 * bulk (autosave) endpointlari + sig'im + shablondan nusxa. Har o'zgarish audit.
 */
class EventController extends HrController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $events = Event::with('venue:id,name,slug')
            ->withCount('groups')
            ->when($request->venue_id, fn ($q, $v) => $q->where('venue_id', $v))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->whereLike('title', "%{$s}%"))
            ->orderByDesc('event_date')
            ->paginate(25);

        return response()->json(['events' => $events, 'filters' => $request->only(['venue_id', 'status', 'search'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Event::class);

        $data = $request->validate([
            'venue_id' => ['required', 'exists:venues,id'],
            'title' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'status' => ['nullable', 'in:draft,confirmed,archived'],
        ]);

        if ($this->tenant()->id() === null) {
            abort(422, 'Аввал ҳокимликни танланг.');
        }

        $event = Event::create([
            ...$data,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $this->actor()->id,
        ]);
        $this->audit($event, 'event.created', ['title' => $event->title]);

        return response()->json(['message' => 'Тадбир яратилди.', 'event' => $event], 201);
    }

    public function show(Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $event->load([
            'venue:id,name,slug',
            'groups',
            'allocations:id,event_id,sector_id,seat_row_id,event_group_id',
        ]);

        return response()->json(['event' => $event]);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'event_date' => ['sometimes', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'status' => ['sometimes', 'in:draft,confirmed,archived'],
        ]);

        $event->update($data);
        $this->audit($event, 'event.updated', $data);

        return response()->json(['message' => 'Тадбир янгиланди.', 'event' => $event]);
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);

        $this->audit($event, 'event.deleted');
        $event->delete();

        return response()->json(['message' => 'Тадбир ўчирилди.']);
    }

    /** Sig'im — guruh bo'yicha belgilangan/bo'sh o'rindiqlar. */
    public function capacity(Event $event, CapacityCalculator $calc): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json($calc->forEvent($event));
    }

    /** Guruhlarni bulk sync (id saqlanadi — belgilashlar yo'qolmaydi). */
    public function syncGroups(Request $request, Event $event): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'groups' => ['present', 'array'],
            'groups.*.id' => ['nullable', 'uuid'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.color' => ['nullable', 'string', 'max:16'],
            'groups.*.expected_count' => ['nullable', 'integer', 'min:0'],
            'groups.*.org_id' => ['nullable', 'uuid'],
            'groups.*.district_id' => ['nullable', 'uuid'],
            'groups.*.sort_order' => ['nullable', 'integer'],
        ]);

        DB::connection('hr')->transaction(function () use ($event, $data) {
            $keepIds = [];
            foreach ($data['groups'] as $i => $g) {
                $attrs = [
                    'name' => $g['name'],
                    'color' => $g['color'] ?? '#2563eb',
                    'expected_count' => $g['expected_count'] ?? null,
                    'org_id' => $g['org_id'] ?? null,
                    'district_id' => $g['district_id'] ?? null,
                    'sort_order' => $g['sort_order'] ?? $i,
                ];
                $model = ! empty($g['id'])
                    ? tap($event->groups()->findOrFail($g['id']))->update($attrs)
                    : $event->groups()->create($attrs);
                $keepIds[] = $model->id;
            }
            // Olib tashlangan guruhlar (va ularning belgilashlari — cascade) o'chadi.
            $event->groups()->whereNotIn('id', $keepIds ?: ['-'])->delete();
        });

        $this->audit($event, 'groups.synced', ['count' => count($data['groups'])]);

        return response()->json(['groups' => $event->groups()->get()]);
    }

    /** Belgilashlarni bulk sync (autosave, replace-all). */
    public function syncAllocations(Request $request, Event $event, AllocationWriter $writer): JsonResponse
    {
        $this->authorize('update', $event);

        $data = $request->validate([
            'allocations' => ['present', 'array'],
            'allocations.*.sector_id' => ['required', 'uuid', 'exists:sectors,id'],
            'allocations.*.seat_row_id' => ['nullable', 'uuid', 'exists:seat_rows,id'],
            'allocations.*.event_group_id' => ['required', 'uuid'],
        ]);

        // Guruhlar shu tadbirга tegishli bo'lsin (IDOR).
        $validGroups = $event->groups()->pluck('id')->all();
        foreach ($data['allocations'] as $a) {
            if (! in_array($a['event_group_id'], $validGroups, true)) {
                abort(422, 'Гуруҳ бу тадбирга тегишли эмас.');
            }
        }

        $count = $writer->sync($event, $data['allocations']);
        $this->audit($event, 'allocations.synced', ['count' => $count]);

        return response()->json(['message' => 'Сақланди.', 'count' => $count]);
    }

    /** Shablon tadbirdan nusxa (guruhlar + belgilashlar bilan). */
    public function duplicate(Request $request, Event $event): JsonResponse
    {
        $this->authorize('create', Event::class);
        $this->authorize('view', $event);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
        ]);

        $new = DB::connection('hr')->transaction(function () use ($event, $data) {
            $new = Event::create([
                'venue_id' => $event->venue_id,
                'title' => $data['title'] ?? ($event->title.' (нусха)'),
                'event_date' => $data['event_date'],
                'start_time' => $data['start_time'] ?? $event->start_time,
                'status' => 'draft',
                'created_by' => $this->actor()->id,
            ]);

            $map = [];
            foreach ($event->groups()->get() as $g) {
                $ng = $new->groups()->create($g->only(['name', 'color', 'expected_count', 'org_id', 'district_id', 'sort_order']));
                $map[$g->id] = $ng->id;
            }
            foreach ($event->allocations()->get() as $a) {
                $new->allocations()->create([
                    'sector_id' => $a->sector_id,
                    'seat_row_id' => $a->seat_row_id,
                    'event_group_id' => $map[$a->event_group_id] ?? null,
                ]);
            }

            return $new;
        });

        $this->audit($new, 'event.duplicated', ['from' => $event->id]);

        return response()->json(['message' => 'Нусха яратилди.', 'event' => $new], 201);
    }

    /**
     * Pechat snapshot — vektor SVG serverда saqlanadi (event_snapshots).
     * Server-PDF (Browsershot) keyingi bosqichда shu SVG'dan render qilinadi.
     */
    public function print(Request $request, Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        $data = $request->validate([
            'svg_content' => ['nullable', 'string'],
            'sheet_format' => ['nullable', 'string', 'max:32'],
        ]);

        $snapshot = EventSnapshot::create([
            'event_id' => $event->id,
            'svg_content' => $data['svg_content'] ?? null,
            'sheet_format' => $data['sheet_format'] ?? null,
            'printed_by' => $this->actor()->id,
            'printed_at' => now(),
        ]);
        $this->audit($event, 'event.printed', ['format' => $snapshot->sheet_format]);

        return response()->json(['message' => 'Снапшот сақланди.', 'snapshot' => $snapshot->only(['id', 'sheet_format', 'printed_at'])], 201);
    }

    private function audit(Event $event, string $action, array $payload = []): void
    {
        EventAuditLog::create([
            'event_id' => $event->id,
            'user_id' => $this->actor()->id,
            'action' => $action,
            'payload_json' => $payload ?: null,
            'created_at' => now(),
        ]);
    }
}
