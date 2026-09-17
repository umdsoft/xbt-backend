<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
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
            'login' => 'pw_'.substr((string) \Illuminate\Support\Str::uuid(), 0, 8),
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

    /**
     * Siyosat: 10 belgi, harf va raqam.
     *
     * Eski qoida `min:8` edi va raqam talab qilmasdi — ya'ni `parolparol`
     * o'tib ketardi.
     */
    public function test_qisqa_yoki_raqamsiz_parol_rad_etiladi(): void
    {
        $user = $this->makeUser('EskiParol1');

        foreach (['Qisqa123', 'FaqatHarflar'] as $weak) {
            $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
                'current_password' => 'EskiParol1',
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertStatus(422)->assertJsonValidationErrors('password');
        }

        $fresh = User::on('auth')->findOrFail($user->id);
        $this->assertTrue(Hash::check('EskiParol1', $fresh->password), 'Parol o‘zgarmasligi kerak edi.');
    }

    /**
     * Login yoki mahalla nomi parol ichida bo'lmasin.
     *
     * Bu qoida validatsiya qoidasidan O'TIB KETADI (uzunlik va tarkib
     * joyida), shuning uchun alohida tekshiriladi.
     */
    public function test_login_asosidagi_parol_rad_etiladi(): void
    {
        $user = User::create([
            'login' => 'shovot_qiyot',
            'name' => 'Парол синови',
            'password' => 'EskiParol1',
            'is_active' => true,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'EskiParol1',
            'password' => 'Qiyot202699',
            'password_confirmation' => 'Qiyot202699',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** Muvaffaqiyatli o'zgarishda sana yoziladi — administrator uchun yagona iz. */
    public function test_ozgarish_sanasi_yoziladi(): void
    {
        $user = $this->makeUser('EskiParol1');

        $this->assertNull($user->password_changed_at);

        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'EskiParol1',
            'password' => 'Bahorgi7Shamol',
            'password_confirmation' => 'Bahorgi7Shamol',
        ])->assertOk();

        $fresh = User::on('auth')->findOrFail($user->id);
        $this->assertNotNull($fresh->password_changed_at);
    }
}
