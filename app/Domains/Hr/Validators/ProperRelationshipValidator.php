<?php

declare(strict_types=1);

namespace App\Domains\Hr\Validators;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Нотўғри қариндошлик сўзларини рад этади.
 * TT бўлим 3.5.1: «ўғлим» → «Ўғли», «рафиқам» → «Турмуш ўртоғи»
 */
class ProperRelationshipValidator implements ValidationRule
{
    /**
     * Тақиқланган сўзлар → тўғри вариант.
     *
     * @var array<string, string>
     */
    private const CORRECTIONS = [
        'ўғлим' => 'Ўғли',
        'қизим' => 'Қизи',
        'рафиқам' => 'Турмуш ўртоғи',
        'хотиним' => 'Турмуш ўртоғи',
        'эрим' => 'Турмуш ўртоғи',
        'отам' => 'Отаси',
        'онам' => 'Онаси',
        'акам' => 'Акаси',
        'опам' => 'Опаси',
        'укам' => 'Укаси',
        'синглим' => 'Синглиси',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $lower = mb_strtolower($value);

        foreach (self::CORRECTIONS as $wrong => $correct) {
            if (mb_strtolower($wrong) === $lower) {
                $fail("«{$value}» нотўғри. Тўғриси: «{$correct}».");

                return;
            }
        }
    }
}
