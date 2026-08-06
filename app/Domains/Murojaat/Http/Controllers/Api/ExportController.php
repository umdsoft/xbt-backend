<?php

declare(strict_types=1);

namespace App\Domains\Murojaat\Http\Controllers\Api;

use App\Domains\Murojaat\Services\AnalyticsService;
use App\Domains\Murojaat\Support\MurojaatAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Joriy (is_active) murojaatlarni CSV eksport (scope kesimida). Excel bu CSV'ni ochadi.
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly MurojaatAccess $access,
        private readonly AnalyticsService $analytics,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->access->can($user, 'murojaat.export'), 403, 'Эксрорт рухсати йўқ.');
        $scope = $this->access->scopeFor($user);
        $ids = $this->analytics->activeSessionIds($scope);

        $cols = ['murojaat_raqami', 'masala_raqami', 'familiya', 'ism', 'mahalla', 'sektor',
            'yonalish', 'natija_holat_norm', 'ijrochi', 'qaerdan', 'kelgan_sana', 'muddat_kun',
            'kechikish_30dan', 'takroriylik', 'jamoaviy'];

        $filename = 'murojaatlar_'.date('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($ids, $cols) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel kirill uchun)
            fputcsv($out, $cols);
            DB::connection('murojaat')->table('appeals')
                ->whereIn('session_id', $ids)
                ->orderByDesc('kelgan_sana_d')
                ->select($cols)->chunk(1000, function ($rows) use ($out, $cols) {
                    foreach ($rows as $r) {
                        fputcsv($out, array_map(fn ($c) => (string) ($r->$c ?? ''), $cols));
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
