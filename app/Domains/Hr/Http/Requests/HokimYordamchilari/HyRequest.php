<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\HokimYordamchilari;

use App\Domains\Hr\Enums\HyDirection;
use App\Domains\Hr\Support\HrAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hokim yordamchisi Store/Update uchun umumiy base — qoidalar bir xil edi (DRY),
 * faqat ruxsat (`create`/`update`) farq qilardi. Subklass `ability()` beradi.
 */
abstract class HyRequest extends FormRequest
{
    /** Talab qilinadigan ruxsat (`hokim-yordamchilari.create` yoki `.update`). */
    abstract protected function ability(): string;

    public function authorize(): bool
    {
        return app(HrAccess::class)->can($this->ability()) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'string', 'exists:users,id'],
            'full_name_cyr' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'direction' => ['required', 'in:'.HyDirection::values()],
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
