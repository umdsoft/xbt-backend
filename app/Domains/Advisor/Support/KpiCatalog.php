<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * KPI KATALOGI — manba: Xorazm viloyati 2026 KPI svodi (Excel namunasi,
 * database/data/advisor_kpi_2026.json). Viloyat varag'i -> viloyat ko'rsatkichlari,
 * tuman varaqlari (birlashma) -> tuman ko'rsatkichlari. Yagona manba: migratsiya
 * ham, KpiCatalogSeeder ham shu ro'yxatdan idempotent upsert qiladi.
 *
 * Kod barqaror: `v_x{raqam}` / `t_x{raqam}` (masalan v_x4_1, t_x10_1) — Excel T/r
 * raqamidan. Nomni tahrirlash dubl yaratmaydi. Ro'yxatда bo'lmagan eski kodlar
 * (avvalgi yo'riqnoma katalogi) DEAKTIVATSIYA qilinadi (active=false) — matritsada
 * ko'rinmaydi, lekin tarixiy yozuvlar saqlanadi.
 */
final class KpiCatalog
{
    /** Excel KPI ma'lumot fayli (viloyat + tumanlar, choraklik reja/bajarilish). */
    public static function dataFile(): string
    {
        return base_path('database/data/advisor_kpi_2026.json');
    }

    /**
     * Xom Excel ma'lumotini o'qiydi (viloyat + districts[soato]).
     *
     * @return array{viloyat: ?array<string,mixed>, districts: array<string, array<string,mixed>>}
     */
    public static function data(): array
    {
        $file = self::dataFile();
        if (! is_file($file)) {
            return ['viloyat' => null, 'districts' => []];
        }

        /** @var array{viloyat: ?array<string,mixed>, districts: array<string, array<string,mixed>>} $d */
        $d = json_decode((string) file_get_contents($file), true) ?: ['viloyat' => null, 'districts' => []];

        return $d;
    }

    /** Excel T/r raqamidan barqaror kod bo'lagi: "4.1" -> "4_1". */
    public static function codeNum(string $num): string
    {
        return str_replace('.', '_', trim($num));
    }

    /**
     * Katalog ro'yxati — [code, name, unit, scope, sort]. Viloyat ko'rsatkichlari +
     * tuman ko'rsatkichlari (barcha tuman varaqlari birlashmasi, viloyat tartibida).
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string, 4: int}>
     */
    public static function items(): array
    {
        $data = self::data();
        $items = [];
        $sort = 0;

        $viloyatInds = $data['viloyat']['indicators'] ?? [];
        foreach ($viloyatInds as $ind) {
            $items[] = [
                'v_x'.self::codeNum($ind['num']),
                self::clip($ind['name'], 255),
                self::clip($ind['unit'] ?? '', 40),
                'viloyat',
                $sort += 10,
            ];
        }

        // Tuman: barcha tuman varaqlari ko'rsatkichlari birlashmasi (num bo'yicha).
        $tumanByNum = [];
        foreach (($data['districts'] ?? []) as $district) {
            foreach ($district['indicators'] ?? [] as $ind) {
                $tumanByNum[$ind['num']] ??= [$ind['name'], $ind['unit'] ?? ''];
            }
        }

        // Tartib: viloyat ko'rsatkich tartibi bo'yicha, keyin qolganlari.
        $ordered = [];
        foreach ($viloyatInds as $ind) {
            if (isset($tumanByNum[$ind['num']])) {
                $ordered[] = $ind['num'];
            }
        }
        foreach (array_keys($tumanByNum) as $num) {
            if (! in_array($num, $ordered, true)) {
                $ordered[] = $num;
            }
        }

        foreach ($ordered as $num) {
            [$name, $unit] = $tumanByNum[$num];
            $items[] = [
                't_x'.self::codeNum((string) $num),
                self::clip($name, 255),
                self::clip($unit, 40),
                'tuman',
                $sort += 10,
            ];
        }

        return $items;
    }

    /**
     * Katalogni advisor.kpis'ga idempotent yozadi (code bo'yicha upsert). Ro'yxatда
     * bo'lmagan mavjud kodlar active=false qilinadi. Faqat PostgreSQL.
     *
     * @return int qayta ishlangan (yaratilgan/yangilangan) KPI soni
     */
    public static function seed(): int
    {
        if (config('database.default') !== 'pgsql') {
            return 0;
        }

        $conn = DB::connection('advisor');
        $now = now();
        $count = 0;
        $codes = [];

        foreach (self::items() as [$code, $name, $unit, $scope, $sort]) {
            $codes[] = $code;
            $payload = [
                'name' => $name,
                'unit' => $unit === '' ? null : $unit,
                'scope' => $scope,
                'sort' => $sort,
                'active' => true,
                'updated_at' => $now,
            ];

            if ($conn->table('kpis')->where('code', $code)->exists()) {
                $conn->table('kpis')->where('code', $code)->update($payload);
            } else {
                $conn->table('kpis')->insert($payload + [
                    'id' => (string) Str::uuid(),
                    'code' => $code,
                    'created_at' => $now,
                ]);
            }

            $count++;
        }

        // Ro'yxatда bo'lmagan eski kodlar (yo'riqnoma katalogi / avto-KPI) — deaktivatsiya.
        if ($codes !== []) {
            $conn->table('kpis')->whereNotIn('code', $codes)->where('active', true)
                ->update(['active' => false, 'updated_at' => $now]);
        }

        // KPI keshini bekor qilish (katalog o'zgardi) — HAM katalog, HAM ma'lumot
        // versiyasini oshirish (active bayrog'i summary/matrix agregatlariga ta'sir
        // qiladi: ular k.active bo'yicha filtrlaydi).
        foreach (['advisor.kpi.catalog.ver', 'advisor.kpi.data.ver'] as $verKey) {
            $ver = (int) Cache::get($verKey, 1);
            Cache::forever($verKey, $ver + 1);
        }

        return $count;
    }

    private static function clip(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }
}
