<?php

declare(strict_types=1);

namespace Tests\Feature\Mahalla;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ҳоким ёрдамчиси микролойиҳаларни ЎЗ маҳалласида юритади.
 *
 * Qamrov — asosiy xavf. Hokim yozish huquqiga ega; qamrov buzilsa boshqa
 * mahallaning loyihasini ko'radi yoki o'zgartiradi.
 */
class HokimProjectTest extends TestCase
{
    use DatabaseTransactions;

    /** Ko'p sxemali: har ulanish alohida qaytarilishi kerak, aks holda
     *  sinov ma'lumoti umumiy dev bazaga sizib qoladi. */
    protected array $connectionsToTransact = ['pgsql', 'auth', 'master', 'mahalla'];

    public function test_hokim_creates_and_lists_projects(): void
    {
        $hokim = $this->makeHokim();

        $this->actingAs($hokim, 'sanctum')
            ->postJson('/api/mahalla/hokim/projects', [
                'title' => 'Гулзор кўчаси йўли',
                'status' => 'in_progress',
                'progress_percent' => 30,
            ])
            ->assertCreated();

        $body = $this->actingAs($hokim, 'sanctum')
            ->getJson('/api/mahalla/hokim/projects')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'status', 'progress_percent']], 'meta' => ['total', 'current_page', 'last_page', 'per_page']])
            ->json();

        $this->assertSame(1, $body['meta']['total']);
    }

    public function test_project_list_is_paginated(): void
    {
        $hokim = $this->makeHokim();
        $mahallaId = DB::connection('mahalla')->table('users')->where('id', $hokim->id)->value('mahalla_id');
        $districtId = DB::connection('master')->table('mahallas')->where('id', $mahallaId)->value('district_id');

        // 25 loyiha — bir sahifadan (20) ko'p.
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'mahalla_id' => $mahallaId, 'district_id' => $districtId,
                'title' => "Лойиҳа {$i}", 'status' => 'planned', 'progress_percent' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::connection('mahalla')->table('micro_projects')->insert($rows);

        $p1 = $this->actingAs($hokim, 'sanctum')->getJson('/api/mahalla/hokim/projects?page=1')->json();
        $this->assertSame(25, $p1['meta']['total']);
        $this->assertSame(2, $p1['meta']['last_page']);
        $this->assertCount(20, $p1['data']);

        $p2 = $this->actingAs($hokim, 'sanctum')->getJson('/api/mahalla/hokim/projects?page=2')->json();
        $this->assertCount(5, $p2['data']);
    }

    /** Marking done sets progress to 100 — "done, lekin 60%" ziddiyati bo'lmasin. */
    public function test_marking_done_forces_full_progress(): void
    {
        $hokim = $this->makeHokim();
        $id = $this->actingAs($hokim, 'sanctum')
            ->postJson('/api/mahalla/hokim/projects', ['title' => 'Тест', 'progress_percent' => 60])
            ->json('id');

        $this->actingAs($hokim, 'sanctum')
            ->patchJson('/api/mahalla/hokim/projects/'.$id, ['status' => 'done'])
            ->assertOk();

        $this->assertSame(100, (int) DB::connection('mahalla')->table('micro_projects')->where('id', $id)->value('progress_percent'));
    }

    public function test_hokim_cannot_open_project_from_another_mahalla(): void
    {
        $hokim = $this->makeHokim();
        $mine = DB::connection('mahalla')->table('users')->where('id', $hokim->id)->value('mahalla_id');

        // Boshqa mahallada loyiha.
        $other = DB::connection('master')->table('mahallas')->where('id', '!=', $mine)->value('id');
        $otherDistrict = DB::connection('master')->table('mahallas')->where('id', $other)->value('district_id');
        $foreignId = (string) Str::uuid();
        DB::connection('mahalla')->table('micro_projects')->insert([
            'id' => $foreignId, 'mahalla_id' => $other, 'district_id' => $otherDistrict,
            'title' => 'Бегона', 'status' => 'planned', 'progress_percent' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($hokim, 'sanctum')
            ->getJson('/api/mahalla/hokim/projects/'.$foreignId)
            ->assertNotFound();

        $this->actingAs($hokim, 'sanctum')
            ->patchJson('/api/mahalla/hokim/projects/'.$foreignId, ['status' => 'done'])
            ->assertNotFound();
    }

    public function test_rais_and_viewer_cannot_reach_hokim(): void
    {
        $this->actingAs($this->makeUser('rais'), 'sanctum')
            ->getJson('/api/mahalla/hokim/overview')->assertForbidden();
        $this->actingAs($this->makeUser('viloyat'), 'sanctum')
            ->getJson('/api/mahalla/hokim/projects')->assertForbidden();
    }

    public function test_update_adds_progress_log(): void
    {
        $hokim = $this->makeHokim();
        $id = $this->actingAs($hokim, 'sanctum')
            ->postJson('/api/mahalla/hokim/projects', ['title' => 'Тест'])->json('id');

        $this->actingAs($hokim, 'sanctum')
            ->postJson('/api/mahalla/hokim/projects/'.$id.'/updates', ['body' => 'Асфальт тўшалди', 'progress_percent' => 70])
            ->assertCreated();

        $show = $this->actingAs($hokim, 'sanctum')->getJson('/api/mahalla/hokim/projects/'.$id)->json();
        $this->assertCount(1, $show['updates']);
        $this->assertSame(70, $show['progress_percent']);
    }

    private function makeHokim(): User
    {
        $user = $this->makeUser('hokim-yordamchisi');
        $mahallaId = DB::connection('master')->table('mahallas')->whereNotNull('district_id')->value('id');
        $districtId = DB::connection('master')->table('mahallas')->where('id', $mahallaId)->value('district_id');

        DB::connection('mahalla')->table('users')->insert([
            'id' => $user->id, 'name' => 'Синов ҳоким', 'login' => 'h_'.substr((string) $user->id, 0, 8),
            'password' => bcrypt('secret'), 'district_id' => $districtId, 'mahalla_id' => $mahallaId,
            'position' => 'hokim_yordamchisi', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeUser(string $role): User
    {
        $userId = (string) Str::uuid();
        $now = now();
        DB::connection('auth')->table('users')->insert([
            'id' => $userId, 'login' => 'test_'.substr($userId, 0, 8),
            'password' => bcrypt('secret'), 'name' => 'ТЕСТ', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::connection('auth')->table('user_system_access')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId,
            'system_id' => DB::connection('auth')->table('systems')->where('code', 'mahalla')->value('id'),
            'role' => $role, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return User::on('auth')->findOrFail($userId);
    }
}
