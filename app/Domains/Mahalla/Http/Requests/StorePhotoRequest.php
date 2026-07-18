<?php

declare(strict_types=1);

namespace App\Domains\Mahalla\Http\Requests;

use App\Domains\Mahalla\Models\House;
use App\Domains\Mahalla\Support\MahallaAccess;
use Illuminate\Foundation\Http\FormRequest;

class StorePhotoRequest extends FormRequest
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

        $house = $this->route('house');
        $houseId = $house instanceof House ? $house->getKey() : $house;

        return House::query()->visibleTo($access->scopeFor($user))->whereKey($houseId)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:baseline,daily'],
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
            'captured_lat' => ['required', 'numeric', 'between:-90,90'],
            'captured_lng' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'taken_date' => ['nullable', 'date'],
            'device_info' => ['nullable', 'array'],
        ];
    }
}
