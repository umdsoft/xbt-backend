<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Support;

use Illuminate\Support\Facades\DB;

/**
 * Tashqi fayllardagi mahalla nomini bazadagi mahallaga bog'laydi.
 *
 * Nomlar hech qachon aynan mos kelmaydi: faylda "Қирғоқ бўйи", bazada
 * "ҚИРҒОҚ БЎЙИ МФЙ"; "Оқкўл (Бўйрачи)" esa rasman "Боғбон" ga o'zgargan.
 * Shuning uchun uch bosqich: normallashtirish -> asosiy nom -> eski nomlar.
 */
class MahallaMatcher
{
    /** @var array<string, string>|null  normalized => mahalla_id */
    private ?array $index = null;

    /** @var array<string, string>  bo'shliqsiz normalized => mahalla_id */
    private array $compact = [];

    /** @var array<string, string|false>  unli yig'ilgan kalit => mahalla_id (false = ikkitalik) */
    private array $folded = [];

    private ?string $districtId = null;

    /**
     * Solishtirish uchun bir ko'rinishga keltiradi: kichik harf, ў/қ/ғ/ҳ
     * soddalashtirilgan, "МФЙ"/"МСГ"/"маҳалласи" qo'shimchalari olib tashlangan.
     */
    public static function normalize(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, [
            'ў' => 'у', 'қ' => 'к', 'ғ' => 'г', 'ҳ' => 'х',
            'ъ' => '', 'ь' => '', 'ё' => 'е',
            '“' => '', '”' => '', '«' => '', '»' => '', '"' => '', "'" => '',
            'ʻ' => '', 'ʼ' => '', '‘' => '', '’' => '',
        ]);
        $s = (string) preg_replace('/\b(мфй|мсг|махалласи|махалла|фуқаролар йигини)\b/u', ' ', $s);
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Unli tebranishini yig'adi, undosh skeletni saqlaydi.
     *
     * O'zbek kirill imlosida bir xil nom turli manbada boshqacha unli bilan
     * yoziladi: РАВОТ/РОВОТ, МАЙЛИ/МОЙЛИ, АШХОБОД/АШХАБОД, САХТИЯН/САХТИЁН,
     * МЕРОБЛАР/МИРОБЛАР. Undoshlar esa deyarli o'zgarmaydi.
     *
     * Shuning uchun unlilar uch guruhga yig'iladi (а-о, е-и-й-ё-я-э, у-ў-ю) va
     * ketma-ket unlilar bittaga qisqartiriladi (ОХУНБОБОЕВ / ОХУНБОБОЙЕВ).
     *
     * DIQQAT: bu ATAYLAB qo'pol. У АНЖИРЧИ va ТАНДИРЧИ ni birlashtirmaydi —
     * undosh skeleti boshqa. Lekin ikki mahalla tasodifan bir kalitga tushishi
     * mumkin, shuning uchun `load()` da faqat YAGONA kalitlar saqlanadi.
     */
    public static function fold(string $name): string
    {
        $s = self::normalize($name);
        $s = (string) preg_replace('/\b(шахарчаси|шаҳарчаси|шфй|кфй|қфй)\b/u', ' ', $s);
        $s = strtr($s, [
            'о' => 'а',
            'и' => 'е', 'й' => 'е', 'ё' => 'е', 'я' => 'е', 'э' => 'е', 'ы' => 'е',
            'ў' => 'у', 'ю' => 'у',
        ]);
        $s = str_replace(' ', '', $s);

        // Ketma-ket unlilarni bittaga tushirish: "баеев" -> "бав".
        return (string) preg_replace('/[аеу]+/u', '$0', preg_replace('/([аеу])[аеу]+/u', '$1', $s));
    }

    /** Tuman doirasida qidiradi (bir xil nom turli tumanlarda uchraydi). */
    public function forDistrict(string $districtId): static
    {
        if ($this->districtId !== $districtId) {
            $this->districtId = $districtId;
            $this->index = null;
            $this->compact = [];
            $this->folded = [];
        }

        return $this;
    }

    /**
     * Nomga mos mahalla `id` sini qaytaradi yoki `null`.
     *
     * Bo'shliqsiz solishtiruv ham sinaladi: manbalar bir xil nomni goh
     * qo'shib, goh ajratib yozadi — "КУМЁП" va "ҚУМ - ЁП", "ЯНГИ ЙУЛ" va
     * "ЯНГИЙЎЛ". Bular bir xil mahalla, faqat yozilishi har xil.
     */
    public function match(string $name): ?string
    {
        $this->load();
        $n = self::normalize($name);

        $hit = $this->index[$n] ?? $this->compact[str_replace(' ', '', $n)] ?? null;
        if ($hit !== null) {
            return $hit;
        }

        // Oxirgi chora: unli yig'ilgan kalit. `false` — shu kalitga tumanda
        // bir nechta mahalla tushgan, ya'ni qaysi biri ekani noaniq. Bunday
        // holatda taxmin qilishdan ko'ra topilmadi deyish to'g'ri.
        $f = $this->folded[self::fold($name)] ?? null;

        return $f === false ? null : $f;
    }

    private function load(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];
        $this->compact = [];

        $mahallas = DB::connection('master')->table('mahallas')
            ->when($this->districtId, fn ($q) => $q->where('district_id', $this->districtId))
            ->get(['id', 'name_cyr', 'name_lat']);

        foreach ($mahallas as $m) {
            foreach ([$m->name_cyr, $m->name_lat] as $n) {
                if ($n) {
                    $key = self::normalize((string) $n);
                    $this->index[$key] = $m->id;
                    $this->compact[str_replace(' ', '', $key)] = $m->id;
                    $this->addFolded(self::fold((string) $n), $m->id);
                }
            }
        }

        // Eski nomlar asosiy nomlardan KEYIN qo'shiladi, lekin ustidan
        // yozmaydi — joriy nom har doim ustun turadi.
        $aliases = DB::connection('master')->table('mahalla_aliases as a')
            ->join('mahallas as m', 'm.id', '=', 'a.mahalla_id')
            ->when($this->districtId, fn ($q) => $q->where('m.district_id', $this->districtId))
            ->get(['a.mahalla_id', 'a.normalized']);

        foreach ($aliases as $a) {
            $this->index[$a->normalized] ??= $a->mahalla_id;
            $this->compact[str_replace(' ', '', $a->normalized)] ??= $a->mahalla_id;
            $this->addFolded(self::fold($a->normalized), $a->mahalla_id);
        }
    }

    /**
     * Yig'ilgan kalitni qo'shadi; ikkinchi mahalla tushsa kalitni yaroqsiz
     * qiladi (`false`) — noaniq kalit bo'yicha taxmin qilinmaydi.
     */
    private function addFolded(string $key, string $mahallaId): void
    {
        if ($key === '') {
            return;
        }

        if (! array_key_exists($key, $this->folded)) {
            $this->folded[$key] = $mahallaId;
        } elseif ($this->folded[$key] !== $mahallaId) {
            $this->folded[$key] = false;
        }
    }
}
