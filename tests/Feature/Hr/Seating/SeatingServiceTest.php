<?php

declare(strict_types=1);

namespace Tests\Feature\Hr\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventAttendee;
use App\Domains\Hr\Models\RowCluster;
use App\Domains\Hr\Models\Sector;
use App\Domains\Hr\Models\Venue;
use App\Domains\Hr\Services\Seating\AllocationWriter;
use App\Domains\Hr\Services\Seating\AttendeeDistributor;
use App\Domains\Hr\Services\Seating\CapacityCalculator;
use App\Domains\Hr\Services\Seating\PlanBuilder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * O'rindiq sxemasi SERVIS testlari (yangi model: seats + row_clusters).
 * Geometriya DWG'dan (AvestoVenueSeeder). Yozuvlar `hr` da tranzaksiya bilan qaytariladi.
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
            'venue_id' => $venue->id, 'hokimlik_id' => $dept,
            'title' => 'TEST tadbir '.uniqid(), 'event_date' => '2026-09-01',
            'status' => 'draft', 'created_by' => $user,
        ]);
    }

    public function test_plan_builder_returns_exact_geometry(): void
    {
        $plan = app(PlanBuilder::class)->build($this->avesto());

        $this->assertSame(2400, $plan['totals']['seats']);
        $this->assertSame(156, $plan['totals']['clusters']);
        $this->assertSame(3, $plan['totals']['sectors']);
        $this->assertArrayHasKey('bbox', $plan);
        $this->assertSame('y', $plan['mirror_axis']['axis']);
        $this->assertArrayHasKey('x', $plan['seats'][0]);
        $this->assertArrayHasKey('rc', $plan['seats'][0]);
    }

    public function test_capacity_cluster_and_whole_sector(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $gA = $event->groups()->create(['name' => 'A', 'color' => '#f00']);
        $gB = $event->groups()->create(['name' => 'B', 'color' => '#00f', 'expected_count' => 10]);

        $secK = Sector::where('venue_id', $venue->id)->where('code', 'K')->firstOrFail();
        $secY = Sector::where('venue_id', $venue->id)->where('code', 'Y')->firstOrFail();
        $rc = RowCluster::where('venue_id', $venue->id)->orderBy('seat_count', 'desc')->first();

        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $secK->id, 'row_cluster_id' => null, 'event_group_id' => $gA->id],
            ['sector_id' => $secY->id, 'row_cluster_id' => $rc->id, 'event_group_id' => $gB->id],
        ]);

        $cap = app(CapacityCalculator::class)->forEvent($event->fresh());
        $kSeats = DB::connection('hr')->table('seats')->where('sector_id', $secK->id)->count();

        $this->assertSame(2400, $cap['capacity']);
        $a = collect($cap['groups'])->firstWhere('id', $gA->id);
        $b = collect($cap['groups'])->firstWhere('id', $gB->id);
        $this->assertSame($kSeats, $a['assigned']);
        $this->assertSame($rc->seat_count, $b['assigned']);
        $this->assertSame($rc->seat_count - 10, $b['diff']);
    }

    public function test_cluster_cannot_be_two_groups(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g1 = $event->groups()->create(['name' => 'A', 'color' => '#f00']);
        $g2 = $event->groups()->create(['name' => 'B', 'color' => '#00f']);
        $sec = Sector::where('venue_id', $venue->id)->where('code', 'K')->firstOrFail();
        $rc = RowCluster::where('venue_id', $venue->id)->first();

        $this->expectException(ValidationException::class);
        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sec->id, 'row_cluster_id' => $rc->id, 'event_group_id' => $g1->id],
            ['sector_id' => $sec->id, 'row_cluster_id' => $rc->id, 'event_group_id' => $g2->id],
        ]);
    }

    public function test_whole_sector_and_cluster_conflict(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g1 = $event->groups()->create(['name' => 'A', 'color' => '#f00']);
        $g2 = $event->groups()->create(['name' => 'B', 'color' => '#00f']);
        $sec = Sector::where('venue_id', $venue->id)->where('code', 'K')->firstOrFail();
        $rc = RowCluster::where('venue_id', $venue->id)->first();

        $this->expectException(ValidationException::class);
        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sec->id, 'row_cluster_id' => null, 'event_group_id' => $g1->id],
            ['sector_id' => $sec->id, 'row_cluster_id' => $rc->id, 'event_group_id' => $g2->id],
        ]);
    }

    public function test_attendee_distribution_fills_seats_in_order(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $g = $event->groups()->create(['name' => 'A', 'color' => '#f00']);
        $rc = RowCluster::where('venue_id', $venue->id)->orderBy('seat_count', 'desc')->first();
        $sec = DB::connection('hr')->table('seats')->where('row_cluster_id', $rc->id)->value('sector_id');

        app(AllocationWriter::class)->sync($event, [
            ['sector_id' => $sec, 'row_cluster_id' => $rc->id, 'event_group_id' => $g->id],
        ]);
        app(AttendeeDistributor::class)->distribute($event, $g, ['Aliyev A', 'Valiyev V', 'Karimov K']);

        $att = EventAttendee::where('event_group_id', $g->id)->orderBy('seat_number')->get();
        $this->assertSame(3, $att->count());
        $this->assertNotNull($att[0]->seat_id);
        $this->assertSame(1, $att[0]->seat_number);
    }
}
