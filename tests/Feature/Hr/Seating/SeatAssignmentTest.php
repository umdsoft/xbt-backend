<?php

declare(strict_types=1);

namespace Tests\Feature\Hr\Seating;

use App\Domains\Hr\Models\Event;
use App\Domains\Hr\Models\EventSeatAssignment;
use App\Domains\Hr\Models\Venue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Per-SEAT biriktirish (event_seat_assignments) API testlari.
 * Geometriya DWG'dan (AvestoVenueSeeder). Yozuvlar `hr` da tranzaksiya bilan qaytariladi.
 * Aktyor — seed qilingan super-admin (cross-tenant, ruxsat bypass). Faqat `hr` ga yoziladi.
 */
class SeatAssignmentTest extends TestCase
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

    private function superAdmin(): User
    {
        $id = DB::connection('hr')->table('model_has_roles as mhr')
            ->join('roles as r', 'r.id', '=', 'mhr.role_id')
            ->where('r.name', 'super-admin')
            ->value('mhr.model_id');

        if ($id === null) {
            $this->markTestSkipped('super-admin roli/foydalanuvchisi yo\'q.');
        }

        $user = User::on('auth')->find($id);
        if ($user === null) {
            $this->markTestSkipped('super-admin auth foydalanuvchisi yo\'q.');
        }

        return $user;
    }

    /** @return array<int, string> */
    private function seatIds(Venue $venue, int $n): array
    {
        return DB::connection('hr')->table('seats')
            ->where('venue_id', $venue->id)
            ->orderBy('code')->limit($n)
            ->pluck('id')->all();
    }

    public function test_assign_three_free_seats_shows_in_seat_map(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seats = $this->seatIds($venue, 3);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", [
                'seat_ids' => $seats,
                'status' => 'occupied',
            ])
            ->assertOk()
            ->assertJsonCount(3, 'assignments')
            ->assertJsonPath("assignments.{$seats[0]}.status", 'occupied')
            ->assertJsonPath("assignments.{$seats[0]}.seat_id", $seats[0]);

        $this->assertSame(3, EventSeatAssignment::where('event_id', $event->id)->count());

        $map = $this->actingAs($this->superAdmin(), 'sanctum')
            ->getJson("/api/hr/events/{$event->id}/seat-map")
            ->assertOk()
            ->assertJsonCount(3, 'assignments')
            ->assertJsonPath('plan.totals.seats', 2440)
            ->json();

        $this->assertArrayHasKey($seats[0], $map['assignments']);
        $this->assertSame('occupied', $map['assignments'][$seats[0]]['status']);
        $this->assertArrayHasKey('assigned_by_name', $map['assignments'][$seats[0]]);
    }

    public function test_named_assignment_to_multiple_seats_is_rejected_422(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seats = $this->seatIds($venue, 2);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", [
                'seat_ids' => $seats,
                'guest_name' => 'Aliyev Akmal',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['seat_ids']);

        $this->assertSame(0, EventSeatAssignment::where('event_id', $event->id)->count());
    }

    public function test_assign_to_occupied_seat_returns_409_and_writes_nothing(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seats = $this->seatIds($venue, 3); // [a, b, c]

        // a ni band qilamiz
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", ['seat_ids' => [$seats[0]]])
            ->assertOk();

        // Endi [a, b, c] — a band → 409, hech narsa yozilmaydi
        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", ['seat_ids' => $seats])
            ->assertStatus(409)
            ->assertJsonPath('occupied.0.seat_id', $seats[0])
            ->assertJsonStructure(['message', 'occupied' => [['seat_id', 'guest_name', 'status']]]);

        // Faqat a bor; b, c yozilmagan
        $this->assertSame(1, EventSeatAssignment::where('event_id', $event->id)->count());
        $this->assertFalse(
            EventSeatAssignment::where('event_id', $event->id)->where('seat_id', $seats[1])->exists()
        );
    }

    public function test_release_removes_assignments(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seats = $this->seatIds($venue, 3);

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", ['seat_ids' => $seats])
            ->assertOk();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/release", ['seat_ids' => $seats])
            ->assertOk()
            ->assertJsonPath('released', 3);

        $this->assertSame(0, EventSeatAssignment::where('event_id', $event->id)->count());
    }

    public function test_patch_updates_guest_name(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seat = $this->seatIds($venue, 1)[0];

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->postJson("/api/hr/events/{$event->id}/seats/assign", [
                'seat_ids' => [$seat], 'status' => 'occupied',
            ])
            ->assertOk();

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->patchJson("/api/hr/events/{$event->id}/seats/{$seat}", [
                'guest_name' => 'Yangi Mehmon',
            ])
            ->assertOk()
            ->assertJsonPath('assignment.guest_name', 'Yangi Mehmon')
            ->assertJsonPath('assignment.seat_id', $seat);

        $this->assertSame(
            'Yangi Mehmon',
            EventSeatAssignment::where('event_id', $event->id)->where('seat_id', $seat)->value('guest_name')
        );
    }

    public function test_patch_creates_assignment_when_missing_upsert(): void
    {
        $venue = $this->avesto();
        $event = $this->makeEvent($venue);
        $seat = $this->seatIds($venue, 1)[0];

        $this->actingAs($this->superAdmin(), 'sanctum')
            ->patchJson("/api/hr/events/{$event->id}/seats/{$seat}", [
                'guest_name' => 'Birlamchi Mehmon',
                'status' => 'reserved',
            ])
            ->assertOk()
            ->assertJsonPath('assignment.guest_name', 'Birlamchi Mehmon')
            ->assertJsonPath('assignment.status', 'reserved');

        $this->assertSame(1, EventSeatAssignment::where('event_id', $event->id)->count());
    }
}
