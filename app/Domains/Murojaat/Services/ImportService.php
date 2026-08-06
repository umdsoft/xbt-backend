<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Services;

use App\Domains\Murojaat\Models\Appeal;
use App\Domains\Murojaat\Models\ImportSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Excel import — client 44 ustunni maydonlarga bog'lab xom qatorlar yuboradi;
 * bu servis normalizatsiya (MurojaatNormalizer) + saqlash (transaction).
 * Yangi sessiya is_active=true; o'sha tumandagi eskilari deactivate (arxiv).
 */
class ImportService
{
    /** Appeals ustunlari (insert whitelist). */
    private const COLUMNS = [
        'tr', 'murojaat_raqami', 'masala_raqami', 'qaerdan', 'kelgan_sana', 'muddat_kun',
        'nazoratchi', 'yuqori_tashkilot', 'ijrochi', 'murojaat_turi', 'jamoaviy',
        'yashash_hudud', 'yashash_tuman', 'sektor', 'mahalla', 'manzil', 'fuqaro_id',
        'familiya', 'ism', 'otasi_ismi', 'telefon', 'jinsi', 'tugilgan_sana', 'bandlik',
        'soha', 'yonalish', 'masala', 'natija_toifa', 'natija_holat', 'javob_kiritilgan',
        'javob_yuborilgan', 'javob_tasdiqlangan', 'korib_chiqish_kun', 'kechikish_30dan',
        'kechikib_yopilgan', 'kechikib_30dan', 'takroriylik', 'kiritgan_tashkilot',
        'sayyor_tashkilot', 'sayyor_rahbar', 'rahbar_lavozim', 'ijrochi_hudud',
        'ijrochi_tuman', 'pinfl',
        'natija_holat_norm', 'is_kechikkan', 'is_sayyor', 'manba_type', 'kun_otgan',
        'kelgan_sana_d', 'kelgan_yil', 'kelgan_oy', 'stat_holat',
    ];

    public function __construct(private readonly MurojaatNormalizer $normalizer) {}

    /**
     * @param  array<int, array<string, mixed>>  $rows  xom mapping qilingan qatorlar
     */
    public function import(array $rows, ?string $districtId, string $fileName, string $userId): ImportSession
    {
        $conn = DB::connection('murojaat');
        $now = Carbon::now();

        return $conn->transaction(function () use ($conn, $rows, $districtId, $fileName, $userId, $now) {
            // Eski active sessiyalarni arxivга (o'sha tuman kesimida).
            $conn->table('import_sessions')
                ->where('is_active', true)
                ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
                ->when($districtId === null, fn ($q) => $q->whereNull('district_id'))
                ->update(['is_active' => false, 'updated_at' => $now]);

            $sayyor = 0;
            $insert = [];
            foreach ($rows as $raw) {
                if (trim((string) ($raw['murojaat_raqami'] ?? '')) === '') {
                    continue; // raqamsiz qatorlar tashlanadi (HTMLdagidek)
                }
                $n = $this->normalizer->normalize($raw);
                if ($n['is_sayyor']) {
                    $sayyor++;
                }
                $row = ['id' => (string) Str::uuid(), 'session_id' => null, 'district_id' => $districtId,
                    'created_at' => $now, 'updated_at' => $now];
                foreach (self::COLUMNS as $c) {
                    $row[$c] = $n[$c] ?? null;
                }
                $insert[] = $row;
            }

            $session = ImportSession::create([
                'district_id' => $districtId,
                'file_name' => $fileName,
                'records_count' => count($insert),
                'sayyor_count' => $sayyor,
                'imported_by' => $userId,
                'is_active' => true,
            ]);

            foreach (array_chunk($insert, 500) as $chunk) {
                foreach ($chunk as &$r) {
                    $r['session_id'] = $session->id;
                }
                unset($r);
                $conn->table('appeals')->insert($chunk);
            }

            return $session;
        });
    }
}
