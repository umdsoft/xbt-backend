<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests;

use App\Domains\Mahalla\Models\Master\Building;
use App\Domains\Mahalla\Support\MahallaAccess;
use App\Domains\Mahalla\Support\MahallaZones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Zona kuzatuvi (rasm) yuklash — deputat FAQAT o'z ko'chalaridagi binoga.
 */
class StoreObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $access = app(MahallaAccess::class);
        if (! $access->can($user, 'photos.upload')) {
            return false;
        }

        $building = $this->route('building');
        $streetId = $building instanceof Building ? $building->street_id : Building::whereKey($building)->value('street_id');

        // Admin — hammasi; deputat — faqat biriktirilgan ko'chalar binolari.
        $scope = $access->scopeFor($user);
        if ($scope->isAdmin) {
            return true;
        }

        return $streetId !== null && in_array($streetId, $scope->streetIds, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone' => ['required', 'string', Rule::in(MahallaZones::zoneCodes())],
            // Bir kuzatuvda 1..N ta RAKURS rasmi (bir zonaning turli burchaklari).
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'captured_lat' => ['required', 'numeric', 'between:-90,90'],
            'captured_lng' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'captured_at' => ['nullable', 'date'], // qurilma vaqti (ma'lumot uchun)
            'device_info' => ['nullable', 'array'],
        ];
    }
}
