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

    /** Tuman doirasida qidiradi (bir xil nom turli tumanlarda uchraydi). */
    public function forDistrict(string $districtId): static
    {
        if ($this->districtId !== $districtId) {
            $this->districtId = $districtId;
            $this->index = null;
            $this->compact = [];
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

        return $this->index[$n]
            ?? $this->compact[str_replace(' ', '', $n)]
            ?? null;
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
        }
    }
}
