<?php

declare(strict_types=1);

namespace App\Domains\Advisor\Http\Controllers\Api;

use App\Domains\Advisor\Services\SvodService;
use App\Domains\Advisor\Support\AdvisorAccess;
use App\Domains\Advisor\Support\Period;
use App\Http\Controllers\Controller;
use App\Support\SimpleXlsx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * YUQORI IDORAГА SVOD eksport (spec §1, §10) — tuman × [topshiriq ijro %, KPI
 * o'rtacha %, reyting o'rni, loyiha soni] xlsx. FAQAT viloyat/bo'linma
 * (oversight.view). Davr berilmasa joriy chorak.
 */
class SvodController extends Controller
{
    public function __construct(
        private readonly AdvisorAccess $access,
        private readonly SvodService $svod,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless($this->access->can($request->user(), 'oversight.view'), 403, 'Свод эксортига рухсат йўқ.');

        $v = $request->validate([
            'period' => ['nullable', 'string', 'max:12'],
        ]);

        $period = $v['period'] ?? Period::current();

        $xlsx = SimpleXlsx::build($this->svod->headers(), $this->svod->rows($period), 'Свод');

        return response($xlsx, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="advisor-svod-'.$period.'.xlsx"',
        ]);
    }
}
