<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Markaziy parol o'zgartirish (`POST /api/change-password`) — barcha tizimlar uchun
 * bitta endpoint. Jorij parol tekshiriladi; yangi parol farq qilishi + tasdiqlanishi shart.
 */
class ChangePasswordTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth'];

    private function makeUser(string $password = 'EskiParol1'): User
    {
        return User::create([
            'login' => 'pw_'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Парол синови',
            'password' => $password, // 'hashed' cast xeshlайди
            'is_active' => true,
        ]);
    }

    public function test_user_can_change_password_with_correct_current(): void
    {
        $user = $this->makeUser('EskiParol1');

        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'EskiParol1',
            'password' => 'YangiParol2',
            'password_confirmation' => 'YangiParol2',
        ])->assertOk()->assertJsonStructure(['message']);

        $fresh = User::on('auth')->findOrFail($user->id);
        $this->assertTrue(Hash::check('YangiParol2', $fresh->password));
        $this->assertFalse(Hash::check('EskiParol1', $fresh->password));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser('EskiParol1');

        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'NotogriParol',
            'password' => 'YangiParol2',
            'password_confirmation' => 'YangiParol2',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $fresh = User::on('auth')->findOrFail($user->id);
        $this->assertTrue(Hash::check('EskiParol1', $fresh->password));
    }

    public function test_new_password_must_differ_and_be_confirmed(): void
    {
        $user = $this->makeUser('EskiParol1');

        // Tasdiq mos emas.
        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'EskiParol1',
            'password' => 'YangiParol2',
            'password_confirmation' => 'BoshqaParol3',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        // Yangi = jorij (farq qilmaydi).
        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'EskiParol1',
            'password' => 'EskiParol1',
            'password_confirmation' => 'EskiParol1',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_change_password_requires_authentication(): void
    {
        $this->postJson('/api/change-password', [
            'current_password' => 'x',
            'password' => 'YangiParol2',
            'password_confirmation' => 'YangiParol2',
        ])->assertStatus(401);
    }
}
