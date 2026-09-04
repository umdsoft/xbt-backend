<?php

declare(strict_types=1);

namespace Tests\Unit\Ayollar;

use App\Domains\Ayollar\Services\RegNumberGenerator;
use Tests\TestCase;

/**
 * Ro'yxat raqami formati — `XOR-08-0142-2026-000731` (promt §7).
 *
 * BU TEST HAQIQIY XATO SABABLI YOZILGAN: `str_pad()` faqat to'ldiradi,
 * kesmaydi. SOATO kodlari (`1733204`, `1733204008`) o'zgarishsiz o'tib
 * ketdi va reyestrda `XOR-1733204-1733204008-2026-000005` ko'rindi —
 * QR ostidagi matnni qo'lda kiritib bo'lmaydigan uzunlikda.
 */
class RegNumberTest extends TestCase
{
    private RegNumberGenerator $gen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = new RegNumberGenerator;
    }

    public function test_prefix_has_fixed_widths(): void
    {
        $this->assertSame('XOR-08-0142-2026-', $this->gen->prefix('8', '142', 2026));
    }

    /** SOATO kodlari KESILADI, cho'zilmaydi. */
    public function test_soato_codes_are_truncated(): void
    {
        $prefix = $this->gen->prefix('1733204', '1733204008', 2026);

        $this->assertSame('XOR-04-4008-2026-', $prefix);
        $this->assertSame(17, strlen($prefix));
    }

    public function test_short_codes_are_padded(): void
    {
        $this->assertSame('XOR-01-0005-2026-', $this->gen->prefix('1', '5', 2026));
    }

    public function test_non_digits_are_dropped(): void
    {
        $this->assertSame('XOR-08-0142-2026-', $this->gen->prefix('D-8', 'MFY 142', 2026));
    }

    public function test_empty_code_does_not_crash(): void
    {
        $this->assertSame('XOR-00-0000-2026-', $this->gen->prefix('', '', 2026));
    }

    /** To'liq raqam 23 belgidan iborat va qat'iy naqshga mos. */
    public function test_full_number_matches_pattern(): void
    {
        $number = $this->gen->prefix('8', '142', 2026).'000731';

        $this->assertSame('XOR-08-0142-2026-000731', $number);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{2}-\d{4}-\d{4}-\d{6}$/', $number);
    }

    public function test_parse_round_trips(): void
    {
        $parsed = $this->gen->parse('XOR-08-0142-2026-000731');

        $this->assertSame('XOR', $parsed['region']);
        $this->assertSame('08', $parsed['district_code']);
        $this->assertSame('0142', $parsed['mahalla_code']);
        $this->assertSame(2026, $parsed['year']);
        $this->assertSame(731, $parsed['sequence']);
    }

    public function test_parse_rejects_malformed(): void
    {
        $this->assertNull($this->gen->parse('XOR-1733204-1733204008-2026-000005'));
        $this->assertNull($this->gen->parse('nonsense'));
    }
}
