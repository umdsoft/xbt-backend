<?php

declare(strict_types=1);

namespace App\Domains\Hr\Http\Requests\YoshlarYetakchilari;

use App\Domains\Hr\Support\HrAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Yoshlar yetakchisi Store/Update uchun umumiy base — qoidalar bir xil edi (DRY),
 * faqat ruxsat (`create`/`update`) farq qilardi. Subklass `ability()` beradi.
 */
abstract class YyRequest extends FormRequest
{
    /** Talab qilinadigan ruxsat (`yoshlar.create` yoki `.update`). */
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
            'mahalla_id' => ['nullable', 'string', 'exists:mahallas,id'],
            'full_name_cyr' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
