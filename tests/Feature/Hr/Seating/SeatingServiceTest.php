<?php

declare(strict_types=1);

namespace Tests\Feature\Hr\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAllocation;
use App\Domains\Hr\Models\EventAttendee;
use App\Domains\Hr\Models\EventAuditLog;
use App\Domains\Hr\Models\EventSnapshot;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Services\Seating\AttendeeDistributor;
use App\Domains\Hr\Models\Venue;
use App\Domains\Hr\Services\Seating\AllocationWriter;
use App\Domains\Hr\Services\Seating\CapacityCalculator;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O'rindiq sxemasi SERVIS testlari (HTTP'siz). Avesto real seed ustida ishlaydi
 * (AvestoVenueSeeder). Yozuvlar `hr` ulanishida tranzaksiya bilan qaytariladi.
 */
class SeatingServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string> */
    protected array $connectionsToTransact = ['hr'];

    private function avesto(): Venue
    {
        $v = Venue::where('slug', 'avesto-katta-zal')->first();
        if ($v === null) {
            $this->markTestSkipped('AvestoVenueSeeder ishga tushirilmagan.');
        }

        return $v;
    }

    private function makeEvent(Venue $venue): Event
    {
        $dept = DB::connection('hr')->table('departments')->value('id');
        $user = DB::connection('hr')->table('users')->value('id');
        if ($dept === null || $user === null) {
            $this->markTestSkipped('departments/users seed yo\'q.');
        }

        return Event::create([
            'venue_id' => $venue->id,
            'hokimlik_id' => $dept,
            'title' => 'TEST tadbir '.uniqid(),
            'event_date' => '2026-09-01',
            'status' => 'draft',
            'created_by' => $user,
        ]);
    }

    public function test_plan_builder_returns_full_capacity(): void
    {
        $plan = app(PlanBuilder::class)->build($this->avesto());

        $this->assertSame(6, $plan['totals']['sectors']);
        $this->assertSame(155, $plan['totals']['rows']);
        $this->assertSame(2384, $plan['totals']['seats']);
        $this->assertNotEmpty($plan['sectors']);
        $this->assertArrayHasKey('anchor_x', $plan['sectors'][0]);
        $this->assertArrayHasKey('rows', $plan['sectors'][0]);
    }

    public function test_capacity_whole_sector_and_single_row(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);

        $groupA = $event->groups()->create(['name' => 'A', 'color' => '#f00']);
        $groupB = $event->groups()->create(['name' => 'B', 'color' => '#00f', 'expected_count' => 10]);

        $sector8 = Sector::where('venue_id', $venue->id)->where('code', 'ONG')->firstOrFail();
        $sector2 = Sector::where('venue_id', $venue->id)->where('code', 'CHAP')->firstOrFail();
        $row = $sector2->seatRows()->orderBy('row_index')->first();

        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sector8->id, 'seat_row_id' => null, 'event_group_id' => $groupA->id],
            ['sector_id' => $sector2->id, 'seat_row_id' => $row->id, 'event_group_id' => $groupB->id],
        ]);

        $cap = app(CapacityCalculator::class)->forEvent($event->fresh());

        $sector8Total = (int) $sector8->seatRows()->sum('seat_count'); // butun sektor (ONG)
        $this->assertSame(2384, $cap['capacity']);
        $this->assertSame($sector8Total + $row->seat_count, $cap['assigned']);
        $this->assertSame(2384 - ($sector8Total + $row->seat_count), $cap['unassigned']);

        $a = collect($cap['groups'])->firstWhere('id', $groupA->id);
        $b = collect($cap['groups'])->firstWhere('id', $groupB->id);
        $this->assertSame($sector8Total, $a['assigned']);
        $this->assertSame($row->seat_count, $b['assigned']);
        $this->assertSame($row->seat_count - 10, $b['diff']);
    }

    public function test_writer_rejects_same_row_in_two_groups(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g1 = $event->groups()->create(['name' => 'G1']);
        $g2 = $event->groups()->create(['name' => 'G2']);
        $sector2 = Sector::where('venue_id', $venue->id)->where('code', 'CHAP')->firstOrFail();
        $row = $sector2->seatRows()->first();

        $this->expectException(ValidationException::class);
        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sector2->id, 'seat_row_id' => $row->id, 'event_group_id' => $g1->id],
            ['sector_id' => $sector2->id, 'seat_row_id' => $row->id, 'event_group_id' => $g2->id],
        ]);
    }

    public function test_writer_rejects_whole_sector_plus_row_of_same_sector(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g1 = $event->groups()->create(['name' => 'G1']);
        $g2 = $event->groups()->create(['name' => 'G2']);
        $sector2 = Sector::where('venue_id', $venue->id)->where('code', 'CHAP')->firstOrFail();
        $row = $sector2->seatRows()->first();

        $this->expectException(ValidationException::class);
        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sector2->id, 'seat_row_id' => null, 'event_group_id' => $g1->id],
            ['sector_id' => $sector2->id, 'seat_row_id' => $row->id, 'event_group_id' => $g2->id],
        ]);
    }

    public function test_print_snapshot_and_audit(): void
    {
        $event = $this->makeEvent($this->avesto());

        $snap = EventSnapshot::create([
            'event_id' => $event->id,
            'svg_content' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
            'sheet_format' => 'A3 landscape',
            'printed_at' => now(),
        ]);
        EventAuditLog::create(['event_id' => $event->id, 'action' => 'event.printed', 'payload_json' => ['format' => 'A3 landscape'], 'created_at' => now()]);

        $this->assertNotNull($snap->id);
        $this->assertSame('A3 landscape', $snap->sheet_format);
        $this->assertSame(1, EventAuditLog::where('event_id', $event->id)->where('action', 'event.printed')->count());
        $this->assertSame(1, EventSnapshot::where('event_id', $event->id)->count());
    }

    public function test_calibrate_updates_geometry_and_busts_cache(): void
    {
        $venue = $this->avesto();
        $s8 = Sector::where('venue_id', $venue->id)->where('code', 'ONG')->firstOrFail();
        $origX = $s8->anchor_x;

        app(PlanBuilder::class)->build($venue); // keshlanadi

        $s8->update(['anchor_x' => $origX + 9999, 'rotation' => 42]);
        $venue->touch(); // plan keshi (updated_at kaliti) yangilanadi

        $after = app(PlanBuilder::class)->build($venue->fresh());
        $s8After = collect($after['sectors'])->firstWhere('code', 'ONG');

        $this->assertSame((float) ($origX + 9999), (float) $s8After['anchor_x']);
        $this->assertSame(42.0, (float) $s8After['rotation']);
    }

    public function test_attendee_distribution_seats_in_order(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g = $event->groups()->create(['name' => 'G']);
        $sector1 = Sector::where('venue_id', $venue->id)->where('code', 'PARTER')->firstOrFail();

        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sector1->id, 'seat_row_id' => null, 'event_group_id' => $g->id],
        ]);

        $count = app(AttendeeDistributor::class)->distribute($event->fresh(), $g->fresh(), ['Aaa', 'Bbb', 'Ccc']);
        $this->assertSame(3, $count);

        $att = EventAttendee::where('event_group_id', $g->id)->orderBy('seat_number')->get();
        $firstRow = $sector1->seatRows()->orderBy('row_index')->first();
        $this->assertSame(3, $att->count());
        $this->assertSame($firstRow->id, $att[0]->seat_row_id);
        $this->assertSame(1, $att[0]->seat_number);
        $this->assertSame('Aaa', $att[0]->full_name);

        // Qayta taqsimlash — dublikat bermaydi (replace-all)
        app(AttendeeDistributor::class)->distribute($event->fresh(), $g->fresh(), ['X', 'Y']);
        $this->assertSame(2, EventAttendee::where('event_group_id', $g->id)->count());
    }

    public function test_writer_replace_all_is_idempotent(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g = $event->groups()->create(['name' => 'G']);
        $sector8 = Sector::where('venue_id', $venue->id)->where('code', 'ONG')->firstOrFail();

        $payload = [['sector_id' => $sector8->id, 'seat_row_id' => null, 'event_group_id' => $g->id]];
        app(AllocationWriter::class)->sync($event, $payload);
        app(AllocationWriter::class)->sync($event, $payload); // qayta — dublikat bermasin

        $this->assertSame(1, EventAllocation::where('event_id', $event->id)->count());
    }
}
