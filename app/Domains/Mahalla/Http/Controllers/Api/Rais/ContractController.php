<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Controllers\Api\Rais;

use App\Domains\Mahalla\Services\ContractService;
use App\Domains\Mahalla\Http\Controllers\Api\MahallaPanelController;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ижтимоий шартнома — хонадон кесимида юклаш.
 *
 * Qamrov (`mahallaId`) foydalanuvchi profilidan. So'rovdan mahalla
 * qabul qilinmaydi — parametr bo'lsa boshqa mahallaga shartnoma yuklash
 * mumkin bo'lardi.
 */
class ContractController extends MahallaPanelController
{
    public function __construct(
        MahallaAccess $access,
        private readonly ContractService $contracts,
    ) {
        parent::__construct($access);
    }

    /** Xonadonlar ro'yxati — har biriga shartnoma soni bilan. */
    public function households(Request $request): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $request->validate([
            'street' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:120'],
            'without' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->contracts->households(
            $mahallaId,
            $v['street'] ?? null,
            (bool) ($v['without'] ?? false) ?: null,
            $v['q'] ?? null,
            (int) ($v['page'] ?? 1),
        ));
    }

    /** Bitta xonadon shartnomalari. */
    public function show(Request $request, string $building): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        // Xonadon shu mahallaga tegishlimi — aks holda boshqa mahalla
        // shartnomalari `?building=` orqali ko'rinib qolardi.
        $belongs = DB::connection('master')->table('buildings')
            ->where('id', $building)->where('mahalla_id', $mahallaId)->exists();
        if (! $belongs) {
            return response()->json(['message' => 'Хонадон топилмади'], 404);
        }

        return response()->json(['contracts' => $this->contracts->forBuilding($building, $mahallaId)]);
    }

    /** Yangi shartnoma + PDF yuklash. */
    public function store(Request $request, string $building): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        $v = $request->validate([
            'contract_number' => ['required', 'string', 'max:60'],
            'contract_type_id' => ['nullable', 'uuid'],
            'signed_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:signed,in_progress,done,cancelled'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Faqat PDF, eng ko'pi 10 MB. `mimetypes` — server tomonda
            // haqiqiy MIME ni tekshiradi, kengaytmaga ishonmaydi.
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $result = $this->contracts->store(
            $mahallaId, $building, $v, $request->file('file'), (string) $request->user()->id,
        );

        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['ok' => true, 'id' => $result['id']], 201);
    }

    /** Shartnomani o'chirish. */
    public function destroy(Request $request, string $contract): JsonResponse
    {
        $mahallaId = $this->mahallaId($request);
        if ($mahallaId === null) {
            return $this->noMahalla();
        }

        if (! $this->contracts->delete($contract, $mahallaId)) {
            return response()->json(['message' => 'Шартнома топилмади'], 404);
        }

        return response()->json(['ok' => true]);
    }

    /** PDF ni maxfiy diskdan uzatadi — URL orqali ochib bo'lmaydi. */
    public function download(Request $request, string $file): StreamedResponse
    {
        $mahallaId = $this->mahallaId($request);
        abort_if($mahallaId === null, 409);

        $meta = $this->contracts->fileFor($file, $mahallaId);
        abort_if($meta === null, 404, 'Файл топилмади');
        abort_unless(Storage::disk($meta['disk'])->exists($meta['path']), 404);

        return Storage::disk($meta['disk'])->download($meta['path'], $meta['name']);
    }

}
