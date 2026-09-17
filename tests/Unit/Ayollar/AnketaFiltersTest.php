<?php

declare(strict_types=1);

namespace Tests\Unit\Ayollar;

use App\Domains\Ayollar\Support\AnketaFilters;
use App\Domains\Ayollar\Support\Rules;
use Tests\TestCase;

/**
 * EHTIYOJ FILTRINING KALITI — INYEKSIYA CHEGARASI.
 *
 * Band raqami JSONB yo'liga, ya'ni SQL MATNIGA tushadi (`answers ->
 * 'q17'`). PostgreSQL da bu yo'lni bog'lanuvchi parametr bilan
 * berib bo'lmaydi, shuning uchun yagona himoya — kalitni QAT'IY
 * tekshirish. Bu test o'sha tekshiruvni qulflaydi: qoida
 * yumshatilsa, shu yerda yiqiladi.
 */
class AnketaFiltersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Rules::flush();
    }

    /** @return array<string, array{string}> */
    public static function badKeyProvider(): array
    {
        return [
            'bo‘sh' => [''],
            'faqat ikki nuqta' => [':texnikum'],
            'q siz' => ['17'],
            'harf' => ['qabc'],
            'uch xonali' => ['q170'],
            'probel' => ['q 17'],
            'inyeksiya' => ["q17' or '1'='1"],
            'izoh' => ['q17--'],
            'nuqtali vergul' => ['q17;drop table anketas'],
            'ro‘yxatda yo‘q band' => ['q11'],
            'mavjud bo‘lmagan band' => ['q99'],
        ];
    }

    /**
     * Noto'g'ri kalit FILTRSIZ qoldirmaydi — u umuman qabul qilinmaydi.
     *
     * Eng xavfli xatti-harakat «jimgina o'tkazib yuborish» emas, balki
     * «filtrsiz ro'yxat berish» edi: foydalanuvchi buni «bu ehtiyoj
     * hammada bor» deb o'qirdi.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badKeyProvider')]
    public function test_yaroqsiz_kalit_rad_etiladi(string $need): void
    {
        $this->assertNull(AnketaFilters::parseNeed($need));
        $this->assertNull(AnketaFilters::needLabel($need));
    }

    public function test_oddiy_band_ajratiladi(): void
    {
        $this->assertSame([15, null], AnketaFilters::parseNeed('q15'));
    }

    public function test_guruhli_band_joyi_bilan_ajratiladi(): void
    {
        $this->assertSame([17, 'texnikum'], AnketaFilters::parseNeed('q17:texnikum'));
    }

    /**
     * Joy qiymati SQL matniga TUSHMAYDI — u bog'lanuvchi parametr.
     * Shuning uchun undagi apostrof ham xavfsiz o'tadi.
     */
    public function test_joy_qiymati_tekshirilmaydi(): void
    {
        $this->assertSame([17, "a'b"], AnketaFilters::parseNeed("q17:a'b"));
    }

    /**
     * Guruhli bandda ehtiyoj ICHKI kalitda.
     *
     * `answers ->> 'q17'` butun JSON satrni qaytaradi va shart hech
     * qachon rost bo'lmasdi — ekranda «ehtiyoj 0» ko'rinardi.
     */
    public function test_guruhli_band_ichki_kalitdan_oqiladi(): void
    {
        $this->assertSame("answers -> 'q17' ->> 'istak'", AnketaFilters::needFlagSql(17));
        $this->assertSame("answers ->> 'q15'", AnketaFilters::needFlagSql(15));
    }

    /** «Ha» ning uchala yozilishi ham qabul qilinadi. */
    public function test_ha_shartida_barcha_yozilishlar_bor(): void
    {
        $sql = AnketaFilters::needYesSql(15);

        foreach (['true', '1', 'ha', 'yes'] as $yes) {
            $this->assertStringContainsString("'{$yes}'", $sql);
        }

        $this->assertStringStartsWith('lower(', $sql);
    }

    /** Yorliq — band matni, guruhli bandda joy nomi bilan. */
    public function test_yorliq_oqiladigan_nom_beradi(): void
    {
        $this->assertSame(Rules::questionTitle(15), AnketaFilters::needLabel('q15'));

        $this->assertSame(
            Rules::questionTitle(17).' — '.Rules::label('texnikum'),
            AnketaFilters::needLabel('q17:texnikum'),
        );
    }
}
