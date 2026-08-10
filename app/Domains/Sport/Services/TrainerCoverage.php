<?php

declare(strict_types=1);

namespace App\Domains\Sport\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sport trenerlar → mahalla QAMROVI tahlili (ochiq portal uchun).
 *
 * `sport` ulanishi search_path = sport,master,public — shuning uchun master
 * jadvallariga (districts/mahallas) to'g'ridan-to'g'ri murojaat qilinadi.
 * PII (telefon) qaytarilmaydi — bu public tahlil.
 */
final class TrainerCoverage
{
    private function db()
    {
        return DB::connection('sport');
    }

    /** Xorazm 13 tumani (tanlagich uchun). */
    public function districts(): array
    {
        return $this->db()->table('master.districts')
            ->orderBy('sort_order')->orderBy('name_cyr')
            ->get(['id', 'name_cyr as name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }

    /** Viloyat darajasidagi umumiy manzara. */
    public function overview(): array
    {
        $trainers = (int) $this->db()->table('trainers')->count();
        $assign = (int) $this->db()->table('trainer_mahallas')->count();
        $matched = (int) $this->db()->table('trainer_mahallas')->whereNotNull('mahalla_id')->count();
        $mahallasTotal = (int) $this->db()->table('master.mahallas')->where('is_active', true)->count();
        $agg = $this->db()->table('trainer_mahallas')
            ->selectRaw('coalesce(sum(youth_7_30),0) youth, coalesce(sum(sport_objects_count),0) objs')
            ->first();

        return [
            'trainers_total' => $trainers,
            'assignments_total' => $assign,
            'mahallas_matched' => $matched,
            'mahallas_total' => $mahallasTotal,
            'coverage_percent' => $mahallasTotal > 0 ? round($matched / $mahallasTotal * 100, 1) : 0,
            'avg_mahallas_per_trainer' => $trainers > 0 ? round($assign / $trainers, 1) : 0,
            'youth_total' => (int) round((float) $agg->youth),
            'sport_objects_total' => (int) $agg->objs,
            'by_district' => $this->byDistrict(),
            'sport_types' => $this->sportTypes(),
            'districts' => $this->districts(),
        ];
    }

    /** Tuman kesimida qamrov. */
    private function byDistrict(): array
    {
        // Har tuman: master mahalla soni, biriktirilган (mos) mahalla, trenerlar, yoshlar.
        $totals = $this->db()->table('master.mahallas')
            ->where('is_active', true)
            ->selectRaw('district_id, count(*) total')
            ->groupBy('district_id')->pluck('total', 'district_id');

        $rows = $this->db()->table('trainer_mahallas as tm')
            ->selectRaw('tm.district_id,
                count(distinct tm.mahalla_id) matched,
                count(distinct tm.trainer_id) trainers,
                coalesce(sum(tm.youth_7_30),0) youth')
            ->groupBy('tm.district_id')->get();

        $names = $this->db()->table('master.districts')->pluck('name_cyr', 'id');

        return $rows->map(function ($r) use ($totals, $names) {
            $total = (int) ($totals[$r->district_id] ?? 0);
            $matched = (int) $r->matched;

            return [
                'district_id' => $r->district_id,
                'name' => $names[$r->district_id] ?? '—',
                'trainers' => (int) $r->trainers,
                'mahallas_matched' => $matched,
                'mahallas_total' => $total,
                'coverage_percent' => $total > 0 ? round($matched / $total * 100, 1) : 0,
                'youth' => (int) round((float) $r->youth),
            ];
        })->sortByDesc('trainers')->values()->all();
    }

    /** Sport turlari taqsimoti (trener soni bo'yicha). */
    private function sportTypes(): array
    {
        return $this->db()->table('trainers')
            ->selectRaw("coalesce(nullif(sport_type,''),'Аниқланмаган') type, count(*) c")
            ->groupBy('type')->orderByDesc('c')->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->c])
            ->all();
    }

    /** Bitta tuman: trenerlar + qamrov bo'shlig'i + mos kelmagan biriktirishlar. */
    public function district(string $districtId): array
    {
        $district = $this->db()->table('master.districts')->where('id', $districtId)->first(['id', 'name_cyr']);
        if ($district === null) {
            return [];
        }

        // Trenerlar + biriktirilган mahallalar (PII/telefon YO'Q).
        $trainers = $this->db()->table('trainers')
            ->where('district_id', $districtId)
            ->orderByRaw("coalesce(nullif(sport_type,''),'яяя')")
            ->get(['id', 'full_name', 'sport_type', 'workplace', 'age', 'uniform_size', 'staff_unit', 'specialization_raw']);

        $assignByTrainer = $this->db()->table('trainer_mahallas')
            ->where('district_id', $districtId)
            ->get(['trainer_id', 'mahalla_id', 'mahalla_name_raw', 'youth_7_30', 'schools', 'sport_objects_count'])
            ->groupBy('trainer_id');

        $trainerList = $trainers->map(function ($t) use ($assignByTrainer) {
            $items = $assignByTrainer[$t->id] ?? collect();

            return [
                'id' => $t->id,
                'full_name' => $t->full_name,
                'sport_type' => $t->sport_type ?: 'Аниқланмаган',
                'workplace' => $t->workplace,
                'age' => $t->age,
                'uniform_size' => $t->uniform_size,
                'mahalla_count' => $items->count(),
                'youth_reach' => (int) round((float) $items->sum('youth_7_30')),
                'sport_objects' => (int) $items->sum('sport_objects_count'),
                'mahallas' => $items->map(fn ($a) => [
                    'name' => $a->mahalla_name_raw,
                    'matched' => $a->mahalla_id !== null,
                    'schools' => $a->schools,
                ])->values()->all(),
            ];
        })->values()->all();

        // Qamrov bo'shlig'i — biriktirilган mahallasi YO'Q master mahallalari.
        $covered = $this->db()->table('trainer_mahallas')
            ->where('district_id', $districtId)->whereNotNull('mahalla_id')
            ->pluck('mahalla_id')->all();
        $uncovered = $this->db()->table('master.mahallas')
            ->where('district_id', $districtId)->where('is_active', true)
            ->when($covered !== [], fn ($q) => $q->whereNotIn('id', $covered))
            ->orderBy('name_cyr')->pluck('name_cyr')->all();

        $mahallasTotal = (int) $this->db()->table('master.mahallas')
            ->where('district_id', $districtId)->where('is_active', true)->count();
        $matched = count($covered);
        $agg = $this->db()->table('trainer_mahallas')->where('district_id', $districtId)
            ->selectRaw('coalesce(sum(youth_7_30),0) youth, coalesce(sum(sport_objects_count),0) objs')->first();

        return [
            'district' => ['id' => $district->id, 'name' => $district->name_cyr],
            'summary' => [
                'trainers' => count($trainerList),
                'mahallas_matched' => $matched,
                'mahallas_total' => $mahallasTotal,
                'coverage_percent' => $mahallasTotal > 0 ? round($matched / $mahallasTotal * 100, 1) : 0,
                'youth_total' => (int) round((float) $agg->youth),
                'sport_objects_total' => (int) $agg->objs,
                'uncovered_count' => count($uncovered),
            ],
            'trainers' => $trainerList,
            'uncovered_mahallas' => $uncovered,
            'sport_types' => $this->districtSportTypes($districtId),
        ];
    }

    private function districtSportTypes(string $districtId): array
    {
        return $this->db()->table('trainers')->where('district_id', $districtId)
            ->selectRaw("coalesce(nullif(sport_type,''),'Аниқланмаган') type, count(*) c")
            ->groupBy('type')->orderByDesc('c')->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->c])
            ->all();
    }
}
