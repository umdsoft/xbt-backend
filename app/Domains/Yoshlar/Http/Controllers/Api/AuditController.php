<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function __invoke(Request $request, YoshlarAccess $access): JsonResponse
    {
        abort_unless($access->can($request->user(), 'yoshlar.audit.view'), 403, 'Ruxsat yo‘q.');

        $rows = DB::connection('yoshlar')->table('audit_log')
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', '100'), 500))
            ->get();

        return response()->json(['data' => $rows]);
    }
}
