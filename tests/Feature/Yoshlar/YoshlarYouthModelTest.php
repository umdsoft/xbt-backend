<?php

declare(strict_types=1);

namespace Tests\Feature\Yoshlar;

use App\Domains\Yoshlar\Models\Youth;
use App\Domains\Yoshlar\Support\Translit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * PII: shifrlash, yashirish, dublikat to'sig'i va yosh hisoblanishi.
 */
class YoshlarYouthModelTest extends YoshlarTestCase
{
    public function test_pinfl_is_hidden_from_serialization(): void
    {
        $youth = $this->makeYouth(['pinfl' => '31234567890123']);

        $array = $youth->toArray();

        $this->assertArrayNotHasKey('pinfl', $array);
        $this->assertArrayNotHasKey('pinfl_hash', $array);
        $this->assertArrayNotHasKey('passport_series', $array);
        $this->assertArrayNotHasKey('passport_number', $array);
    }

    public function test_pinfl_is_stored_encrypted_but_readable_via_model(): void
    {
        $youth = $this->makeYouth(['pinfl' => '31234567890123']);

        $raw = DB::connection('yoshlar')->table('youth')->where('id', $youth->id)->value('pinfl');

        $this->assertNotSame('31234567890123', $raw, 'PINFL ochiq matnda saqlanibdi');
        $this->assertSame('31234567890123', $youth->fresh()->pinfl);
    }

    public function test_duplicate_pinfl_is_rejected(): void
    {
        $this->makeYouth(['pinfl' => '31234567890999']);

        $this->expectException(QueryException::class);

        $this->makeYouth(['pinfl' => '31234567890999']);
    }

    public function test_full_name_norm_is_generated_and_searchable_in_cyrillic(): void
    {
        $youth = $this->makeYouth(['last_name' => 'Sharipov', 'first_name' => 'Otabek']);

        $norm = DB::connection('yoshlar')->table('youth')->where('id', $youth->id)->value('full_name_norm');

        $this->assertStringContainsString('SHARIPOV', (string) $norm);

        // Kirillcha qidiruv lotin normga o'giriladi.
        $this->assertStringContainsString(Translit::normalize('Шарипов'), (string) $norm);
    }

    public function test_age_between_scope_respects_boundaries(): void
    {
        $young = $this->makeYouth(['birth_date' => now()->subYears(14)->toDateString()]);
        $old = $this->makeYouth(['birth_date' => now()->subYears(31)->subDay()->toDateString()]);

        $ids = Youth::query()->ageBetween(14, 30)->pluck('id')->all();

        $this->assertContains($young->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    /** @param array<string, mixed> $attrs */
    private function makeYouth(array $attrs = []): Youth
    {
        $districtId = $this->someDistrictId();

        return Youth::query()->create(array_merge([
            'last_name' => 'Testov',
            'first_name' => 'Test',
            'birth_date' => now()->subYears(20)->toDateString(),
            'gender' => 'erkak',
            'district_id' => $districtId,
            'mahalla_id' => $this->someMahallaId($districtId),
        ], $attrs));
    }
}
