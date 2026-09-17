<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KIRISH CHEKLOVI HISOB BO'YICHA — QO'SHNI BLOKLANMASIN.
 *
 * Bu test bitta xatti-harakatni qulflaydi: A hisobiga qilingan xato
 * urinishlar B hisobining to'g'ri parolini bloklamasligi kerak.
 *
 * Nega muhim: Xorazmda MFY faollari hokimiyat va mahalla
 * idoralaridan kiradi — o'nlab odam bitta tashqi IP ortida. Eski
 * `throttle:5,1` faqat IPni sanardi va bir faolning uchta xatosi
 * qo'shnisini ham bloklardi. Faol buni «hisobim ishlamayapti» deb
 * tushunardi va tuman ma'muriga murojaat qilardi.
 */
class LoginThrottleTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['pgsql', 'auth'];

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
    }

    private function makeUser(string $password): User
    {
        return User::create([
            'login' => 'thr_'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Кириш синови',
            'password' => $password,
            'is_active' => true,
        ]);
    }

    /** Bitta hisobga parol terish — beshinchi urinishdan keyin to'xtaydi. */
    public function test_bir_hisobga_kop_urinish_bloklanadi(): void
    {
        $user = $this->makeUser('TogriParol1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'login' => $user->login,
                'password' => 'NotogriParol'.$i,
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'login' => $user->login,
            'password' => 'NotogriParol9',
        ])->assertStatus(429);
    }

    /**
     * ENG MUHIM TEKSHIRUV: qo'shni hisob bloklanmaydi.
     *
     * Ikkala so'rov ham bir xil IPdan keladi (test klienti bitta), ya'ni
     * eski IP-asosli cheklovда bu test ALBATTA yiqilardi.
     */
    public function test_qoshni_hisob_bloklanmaydi(): void
    {
        $a = $this->makeUser('ParolA1');
        $b = $this->makeUser('ParolB1');

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['login' => $a->login, 'password' => 'xato'.$i]);
        }

        $this->postJson('/api/login', [
            'login' => $b->login,
            'password' => 'ParolB1',
        ])->assertOk();
    }

    /** Bloklanganda javob O'QILADIGAN matn va kutish vaqtini beradi. */
    public function test_blok_xabari_oqiladigan_matn_beradi(): void
    {
        $user = $this->makeUser('TogriParol1');

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['login' => $user->login, 'password' => 'xato'.$i]);
        }

        $response = $this->postJson('/api/login', [
            'login' => $user->login,
            'password' => 'xato',
        ])->assertStatus(429)->assertJsonStructure(['message', 'retry_after']);

        // Inglizcha standart matn EMAS — MFY faoli uni o'qiy olmasdi.
        $this->assertStringNotContainsString('Too Many Attempts', $response->json('message'));
        $this->assertGreaterThan(0, $response->json('retry_after'));
    }

    /** Harf registri bilan chegarani aylanib o'tib bo'lmaydi. */
    public function test_harf_registri_chegarani_aylanmaydi(): void
    {
        $user = $this->makeUser('TogriParol1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => $user->login, 'password' => 'xato'.$i]);
        }

        $this->postJson('/api/login', [
            'login' => mb_strtoupper($user->login),
            'password' => 'xato',
        ])->assertStatus(429);
    }
}
