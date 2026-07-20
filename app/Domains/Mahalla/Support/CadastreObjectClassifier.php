<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kadastrdagi ERKIN MATN bino vazifasini (`MAQSAD`) toifaga bog'laydi.
 *
 * Manba juda tartibsiz: Shovotda 1617 obyektga 1080 xil qiymat. Bir xil
 * narsa turlicha yozilgan — "ДЎКОН БИНОСИ", "Дукон биноси", "\"Дўкон\" биноси".
 * Shuning uchun matn avval NORMALLASHTIRILADI, keyin kalit so'z qidiriladi.
 *
 * Qoidalar `master.object_types` jadvalida — kodda emas. Yangi toifa yoki
 * yangi imlo varianti chiqsa, dastur o'zgartirilmaydi.
 */
class CadastreObjectClassifier
{
    /** @var array<int, object>|null */
    private ?array $types = null;

    /** @var array<string, string> */
    private array $codeById = [];

    private ?string $fallbackId = null;

    /**
     * Matnni solishtirish uchun bir ko'rinishga keltiradi.
     *
     * Kirill o'zbekda bir tovush ikki xil yoziladi (ў/у, қ/к, ғ/г, ҳ/х) va
     * kadastrda ikkalasi ham uchraydi. Ularni bittaga keltirmasak, har imlo
     * varianti uchun alohida kalit so'z yozishga to'g'ri kelardi.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'ў' => 'у', 'қ' => 'к', 'ғ' => 'г', 'ҳ' => 'х',
            'ъ' => '', 'ь' => '', 'ё' => 'е',
            '“' => '', '”' => '', '«' => '', '»' => '', '"' => '', "'" => '',
            'ʻ' => '', 'ʼ' => '', '‘' => '', '’' => '',
            '-' => ' ', '.' => ' ', ',' => ' ',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Matnga mos toifa `id` sini qaytaradi (topilmasa — «бошқа»). */
    public function classify(string $maqsad): ?string
    {
        $this->load();

        $norm = self::normalize($maqsad);
        if ($norm === '') {
            return $this->fallbackId;
        }

        // Toifalar `sort_order` bo'yicha tekshiriladi: aniqrog'i (мактаб)
        // umumiyroq (маъмурий бино) dan oldin turadi, shuning uchun
        // "спорт мактаби" маъмурий эмас, мактаб бўлиб чиқади.
        foreach ($this->types as $t) {
            foreach ($t->keywordList as $kw) {
                if ($kw !== '' && str_contains($norm, $kw)) {
                    return $t->id;
                }
            }
        }

        return $this->fallbackId;
    }

    public function codeFor(?string $typeId): string
    {
        $this->load();

        return $typeId === null ? 'boshqa' : ($this->codeById[$typeId] ?? 'boshqa');
    }

    private function load(): void
    {
        if ($this->types !== null) {
            return;
        }

        $rows = DB::connection('master')->table('object_types')
            ->orderBy('sort_order')
            ->get(['id', 'code', 'keywords']);

        $this->types = [];
        foreach ($rows as $r) {
            $this->codeById[$r->id] = $r->code;

            if ($r->code === 'boshqa') {
                $this->fallbackId = $r->id;
            }

            $list = json_decode((string) $r->keywords, true) ?: [];
            $r->keywordList = array_map(self::normalize(...), $list);
            $this->types[] = $r;
        }
    }
}
