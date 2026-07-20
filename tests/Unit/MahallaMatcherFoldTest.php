<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Mahalla\Support\MahallaMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `fold()` unli tebranishini yig'adi — bu ATAYLAB qo'pol amal.
 *
 * Uning qiymati ham, xavfi ham bir joyda: juda ko'p narsani birlashtirsa
 * turli mahallalarni bir-biriga qo'shib yuboradi. Shuning uchun ikkala
 * tomon ham test bilan mahkamlanadi — birlashishi kerak bo'lganlar VA
 * ajralib turishi kerak bo'lganlar.
 */
class MahallaMatcherFoldTest extends TestCase
{
    /**
     * Bir xil mahalla, manbalarda turlicha yozilgan.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameNames(): array
    {
        return [
            'а/о unli' => ['АШХОБОД МФЙ', 'Ашхабод МФЙ'],
            'а/о ikkinchi bo\'g\'in' => ['РАВОТ МФЙ', 'РОВОТ МФЙ'],
            'а/о + й' => ['МАЙЛИ МФЙ', 'МОЙЛИ МФЙ'],
            'я/ё' => ['САХТИЯН МФЙ', 'САХТИЁН МФЙ'],
            'е/и' => ['МЕРОБЛАР МФЙ', 'МИРОБЛАР МФЙ'],
            'ҳ/х va о/а' => ['ХАЗАРАСП МФЙ', 'ҲАЗОРАСП МФЙ'],
            'йе/е qo\'shaloq unli' => ['ЙЎЛДОШ ОХУНБОБОЕВ МФЙ', 'ЙЎЛДОШ ОХУНБОБОЙЕВ МФЙ'],
            'bir nechta farq' => ['КОМИЛЖОН ОТАНИЁЗОВ МФЙ', 'КОМИЛЖОН АТАНИЯЗОВ МФЙ'],
            'bo\'shliq' => ['ЯНГИ ЙУЛ МФЙ', 'ЯНГИЙЎЛ МФЙ'],
            'ъ/ь' => ['ОЛТИНКАЛЬА МФЙ', 'ОЛТИНҚАЛЪА МФЙ'],
        ];
    }

    #[DataProvider('sameNames')]
    public function test_orthographic_variants_fold_together(string $a, string $b): void
    {
        $this->assertSame(
            MahallaMatcher::fold($a),
            MahallaMatcher::fold($b),
            "«{$a}» va «{$b}» bir mahalla — yig'ilgan kaliti bir xil bo'lishi kerak",
        );
    }

    /**
     * Boshqa-boshqa mahallalar. Bular birlashib ketsa import xato juftlik
     * yasaydi va statistika boshqa mahallaga yoziladi.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differentNames(): array
    {
        return [
            // 86% o'xshash, lekin boshqa mahalla — o'xshashlikka tayangan
            // moslashtirish aynan shu yerda yiqiladi.
            'undosh skeleti boshqa' => ['АНЖИРЧИ МФЙ', 'ТАНДИРЧИ МФЙ'],
            'ko\'plik qo\'shimchasi' => ['ШОЛИКОР МФЙ', 'ШОЛИКОРЛАР МФЙ'],
            'qisqa/uzun' => ['АРБЕК МФЙ', 'АРБОБ МФЙ'],
            'boshqa so\'z' => ['ГУЛИСТОН МФЙ', 'ГУЛЗОР МФЙ'],
            'bir harf farq, boshqa ma\'no' => ['НАВРЎЗ МФЙ', 'НАВБАҲОР МФЙ'],
        ];
    }

    #[DataProvider('differentNames')]
    public function test_distinct_mahallas_stay_distinct(string $a, string $b): void
    {
        $this->assertNotSame(
            MahallaMatcher::fold($a),
            MahallaMatcher::fold($b),
            "«{$a}» va «{$b}» boshqa mahalla — yig'ilish ularni qo'shmasligi kerak",
        );
    }

    public function test_type_suffixes_are_stripped(): void
    {
        // "МФЙ", "шаҳарчаси", "ШФЙ" — hudud turi, nomning bir qismi emas.
        $bare = MahallaMatcher::fold('НУРАФШОН');

        $this->assertSame($bare, MahallaMatcher::fold('НУРАФШОН МФЙ'));
        $this->assertSame($bare, MahallaMatcher::fold('НУРАФШОН ШАХАРЧАСИ МФЙ'));
    }
}
