<?php

declare(strict_types=1);

namespace Tests\Feature\Murojaat;

use App\Domains\Murojaat\Services\MurojaatNormalizer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Normalizatsiya biznes-qoidalari (HTMLdagi normalizeRow) — holat, kechikkan,
 * sayyor, manba, statistika toifasi, sana parse.
 */
class MurojaatNormalizerTest extends TestCase
{
    private function norm(array $r): array
    {
        return app(MurojaatNormalizer::class)->normalize($r);
    }

    public function test_javob_yoq_15_kundan_oshgan_kechikkan(): void
    {
        $eski = Carbon::now()->subDays(40)->format('d.m.Y');
        $n = $this->norm(['murojaat_raqami' => '1', 'natija_holat' => '', 'kelgan_sana' => $eski]);

        $this->assertTrue($n['is_kechikkan']);
        $this->assertSame('kechikkan', $n['natija_holat_norm']);
        $this->assertGreaterThanOrEqual(30, $n['kun_otgan']);
    }

    public function test_kechikish_30dan_kechikkan_qiladi(): void
    {
        $n = $this->norm(['murojaat_raqami' => '1', 'natija_holat' => 'Ижобий ҳал этилди',
            'kelgan_sana' => Carbon::now()->format('d.m.Y'), 'kechikish_30dan' => '5']);
        $this->assertTrue($n['is_kechikkan']);
    }

    public function test_holat_normalizatsiyasi(): void
    {
        $this->assertSame('hal', $this->norm(['murojaat_raqami' => '1', 'natija_holat' => 'Ижобий ҳал этилди'])['natija_holat_norm']);
        $this->assertSame('rad', $this->norm(['murojaat_raqami' => '1', 'natija_holat' => 'Рад этилди'])['natija_holat_norm']);
        $this->assertSame('yonaltirildi', $this->norm(['murojaat_raqami' => '1', 'natija_holat' => 'Бошқа ташкилотга йўналтирилди'])['natija_holat_norm']);
        $this->assertSame('jarayon', $this->norm(['murojaat_raqami' => '1', 'natija_holat' => 'Ижрода', 'kelgan_sana' => Carbon::now()->format('d.m.Y')])['natija_holat_norm']);
    }

    public function test_javob_tasdiqlangan_hal_qiladi(): void
    {
        $n = $this->norm(['murojaat_raqami' => '1', 'natija_holat' => '', 'javob_tasdiqlangan' => '05.03.2026',
            'kelgan_sana' => Carbon::now()->format('d.m.Y')]);
        $this->assertSame('hal', $n['natija_holat_norm']);
        $this->assertFalse($n['is_kechikkan']);
    }

    public function test_sayyor_va_manba_va_stat(): void
    {
        $n = $this->norm(['murojaat_raqami' => '1', 'sayyor_tashkilot' => 'Урганч тумани ҳокимлиги',
            'qaerdan' => 'Президент виртуал қабулхонаси', 'natija_holat' => 'Ижобий ҳал этилди']);
        $this->assertTrue($n['is_sayyor']);
        $this->assertSame('pvq', $n['manba_type']);
        $this->assertSame('ijobiy', $n['stat_holat']);

        $xq = $this->norm(['murojaat_raqami' => '1', 'qaerdan' => 'Халқ қабулхонаси']);
        $this->assertSame('xq', $xq['manba_type']);
    }

    public function test_sana_parse(): void
    {
        $n = $this->norm(['murojaat_raqami' => '1', 'kelgan_sana' => '15.02.2026']);
        $this->assertSame(2026, $n['kelgan_yil']);
        $this->assertSame(2, $n['kelgan_oy']);
        $this->assertSame('2026-02-15', $n['kelgan_sana_d']);
    }

    public function test_takroriylik_va_jamoaviy(): void
    {
        $n = $this->norm(['murojaat_raqami' => '1', 'takroriylik' => 'Такрорий мурожаат', 'jamoaviy' => 'Ҳа']);
        $this->assertSame('Takroriy', $n['takroriylik']);
        $this->assertSame('Ha', $n['jamoaviy']);
    }
}
