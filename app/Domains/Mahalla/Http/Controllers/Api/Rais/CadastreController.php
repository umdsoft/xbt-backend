<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Rais;

use App\Domains\Mahalla\Services\ExecutiveStats;
use App\Domains\Mahalla\Services\RaisCadastre;
use App\Domains\Mahalla\Http\Controllers\Api\MahallaPanelController;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Маҳалла раиси панели — ўз маҳалласи бўйича тўлиқ маълумот ва тузатиш.
 *
 * Qamrov bitta joydan olinadi: `MahallaAccess::scopeFor()` bergan
 * `mahallaId`. So'rovdan mahalla qabul qilinmaydi — aks holda rais
 * parametrni almashtirib boshqa mahallani ochib olardi.
 */
class CadastreController extends MahallaPanelController
{
    public function __construct(
        MahallaAccess $access,
        private readonly RaisCadastre $cadastre,
        private readonly ExecutiveStats $stats,
    ) {
        parent::__construct($access);
    }

    /** Mahalla haqida umumiy ma'lumot: ko'rsatkichlar, obyektlar, tuzatishlar. */
    public function overview(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $m = DB::connection('master')->table('mahallas as m')
            ->leftJoin('districts as d', 'd.id', '=', 'm.district_id')
            ->where('m.id', $mahallaId)
            ->first(['m.id', 'm.name_cyr', 'm.soato_code', 'd.id as district_id', 'd.name_cyr as district_name']);

        $data = $this->stats->mahalla($mahallaId);

        return response()->json([
            'mahalla' => [
                'id' => $m?->id,
                'name' => $m?->name_cyr,
                'soato' => $m?->soato_code,
                'district' => ['id' => $m?->district_id, 'name' => $m?->district_name],
            ],
            'households' => $data['households'],
            'indicators' => $data['indicators'],
            'social_objects' => $data['social_objects'],
            'zones' => $data['rows'],
            // Xarita uchun: o'z chegarasi va ustidagi ijtimoiy obyektlar.
            // Ikkalasi ham bitta javobda — rais sahifasi ochilishi bilan
            // xarita to'liq ko'rinsin, ketma-ket so'rov kutmasin.
            'boundary' => $this->cadastre->boundary($mahallaId),
            'social_points' => $this->cadastre->socialPoints($mahallaId),
            // Ko'chalar kesimi — bosh panel jadvali (tuman panelidagi
            // mahallalar kesimiga o'xshash, faqat ko'cha darajasida).
            'streets' => $this->cadastre->streetsBreakdown($mahallaId),
            'recent_changes' => $this->cadastre->recentChanges($mahallaId),
            // `object_types` da `is_active` ustuni YO'Q — ro'yxat qisqa va
            // to'liq ishlatiladi, o'chirilgan tur tushunchasi kiritilmagan.
            'object_types' => DB::connection('master')->table('object_types')
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name_cyr as name', 'is_social']),
            // Shartnoma turlari — yuklash oynasi uchun.
            'contract_types' => DB::connection('master')->table('contract_types')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'code', 'name_cyr as name']),
        ]);
    }

    /** Kadastr binolari ro'yxati — qidiruv va suzgich bilan. */
    public function buildings(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'string', 'max:40'],
            'unclassified' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->cadastre->buildings(
            $mahallaId,
            $validated['q'] ?? null,
            $validated['type'] ?? null,
            (bool) ($validated['unclassified'] ?? false),
            (int) ($validated['page'] ?? 1),
        ));
    }

    /** Bino turini tuzatadi. */
    public function classify(Request $request, string $building): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $validated = $request->validate([
            // `null` — turni olib tashlash (noto'g'ri tasniflangan bo'lsa).
            'type_id' => ['present', 'nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $ok = $this->cadastre->classify(
            $building,
            $mahallaId,
            $validated['type_id'],
            (string) $request->user()->id,
            $validated['note'] ?? null,
        );

        if (! $ok) {
            // 404, 403 emas: bino bu mahallada yo'qligini bildirish ham
            // ortiqcha ma'lumot — boshqa mahallada borligini oshkor qiladi.
            return response()->json(['message' => 'Бино топилмади'], 404);
        }

        return response()->json(['ok' => true]);
    }

}
