<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Http\Controllers\Api;

use App\Domains\Ayollar\Support\Rules;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/ayollar/rules` — toifalash qoidalari.
 *
 * Frontend jonli toifa chizig'ini AYNAN shu fayl bilan hisoblaydi (promt
 * §10.10 va §14). Mantiq TypeScript'da qayta yozilsa, ikki nusxa vaqt
 * o'tib bir-biridan uzoqlashardi: planshetda «yashil», serverda «sariq».
 *
 * `ETag` bilan: qoida deyarli o'zgarmaydi va har ochilishda qayta
 * yuklanishi shart emas.
 */
class RulesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $rules = Rules::all();
        $etag = '"'.Rules::version().'-'.substr(md5((string) json_encode($rules)), 0, 12).'"';

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)->header('ETag', $etag);
        }

        return response()->json($rules)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=300');
    }
}
