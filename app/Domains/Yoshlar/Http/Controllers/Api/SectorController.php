<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Http\Controllers\Api;

use App\Domains\Yoshlar\Models\Sector;
use App\Domains\Yoshlar\Support\Translit;
use App\Domains\Yoshlar\Support\YoshlarAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectorController extends Controller
{
    public function __construct(private readonly YoshlarAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.view'), 403, 'Ruxsat yo‘q.');

        return response()->json(['data' => Sector::query()->orderBy('sort_order')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:yoshlar.sectors,code'],
            'name_lat' => ['required', 'string', 'max:300'],
            'name_cyr' => ['nullable', 'string', 'max:300'],
            'sort_order' => ['integer'],
        ]);

        // Kirill nomi berilmasa lotindan hosil qilinadi (keyin tahrirlanadi).
        $data['name_cyr'] = $data['name_cyr'] ?? Translit::toCyr($data['name_lat']);

        return response()->json(['data' => Sector::query()->create($data)], 201);
    }

    public function update(Request $request, string $sector): JsonResponse
    {
        $this->authorizeManage($request);

        $model = Sector::query()->findOrFail($sector);
        $data = $request->validate([
            'name_lat' => ['sometimes', 'string', 'max:300'],
            'name_cyr' => ['sometimes', 'string', 'max:300'],
            'sort_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $model->update($data);

        return response()->json(['data' => $model->refresh()]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->access->can($request->user(), 'yoshlar.org.manage'), 403, 'Ruxsat yo‘q.');
    }
}
