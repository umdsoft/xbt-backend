<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Rais;

use App\Domains\Mahalla\Http\Controllers\Api\MahallaPanelController;
use App\Domains\Mahalla\Services\StreetEditor;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Маҳалла раиси — кўчаларни таҳрирлаш ва уйларни кўчага бириктириш.
 *
 * Qamrov faqat profildagi mahalla — so'rovda `{mahalla}` yo'q. Har amalda
 * ko'cha/bino shu mahallaga tegishliligi StreetEditor'da tekshiriladi.
 */
class StreetController extends MahallaPanelController
{
    public function __construct(MahallaAccess $access, private readonly StreetEditor $editor)
    {
        parent::__construct($access);
    }

    /** Ko'chalar ro'yxati — uy soni va rang bilan. */
    public function index(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        return response()->json($this->editor->streets($mahallaId));
    }

    /** Xarita: residential binolar (koordinatali) + chegara. */
    public function map(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        return response()->json($this->editor->mapData($mahallaId));
    }

    /** Yangi ko'cha. */
    public function store(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        $street = $this->editor->create($mahallaId, $data['name'], (string) $request->user()->id);
        if ($street === null) {
            return response()->json(['message' => 'Бу номли кўча аллақачон бор'], 422);
        }

        return response()->json(['street' => $street], 201);
    }

    /** Ko'cha nomini o'zgartirish. */
    public function update(Request $request, string $street): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $data = $request->validate(['name' => ['required', 'string', 'max:200']]);

        if (! $this->editor->rename($mahallaId, $street, $data['name'], (string) $request->user()->id)) {
            return response()->json(['message' => 'Ном ўзгартириб бўлмади (топилмади ёки ном банд)'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** A ko'chани B ga birlashtirish. */
    public function merge(Request $request, string $street): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $data = $request->validate(['target_id' => ['required', 'uuid']]);

        if (! $this->editor->merge($mahallaId, $street, $data['target_id'], (string) $request->user()->id)) {
            return response()->json(['message' => 'Бирлаштириб бўлмади'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Ko'chani o'chirish (faqat bo'sh bo'lsa). */
    public function destroy(Request $request, string $street): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $result = $this->editor->deleteStreet($mahallaId, $street, (string) $request->user()->id);

        return match ($result) {
            'ok' => response()->json(['ok' => true]),
            'not_empty' => response()->json(['message' => 'Кўчада уйлар бор — аввал бошқа кўчага кўчиринг ёки бирлаштиринг'], 409),
            default => response()->json(['message' => 'Кўча топилмади'], 404),
        };
    }

    /** Bino(lar)ni ko'chaga biriktirish (xaritadan). */
    public function assign(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $data = $request->validate([
            'street_id' => ['required', 'uuid'],
            'building_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'building_ids.*' => ['uuid'],
        ]);

        $count = $this->editor->assign($mahallaId, $data['building_ids'], $data['street_id'], (string) $request->user()->id);
        if ($count === null) {
            return response()->json(['message' => 'Кўча ёки бино топилмади'], 404);
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }
}
