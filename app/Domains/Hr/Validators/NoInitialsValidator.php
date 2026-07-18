<?php

declare(strict_types=1);

namespace App\Domains\Hr\Validators;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Инициалларни рад этади.
 * TT бўлим 3.5.1: «Рўзиев А.Ҳ.» → рад, тўлиқ исм талаб қилинади.
 */
class NoInitialsValidator implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // Кирилл инициаллар: бир ёки икки катта ҳарф + нуқта
        if (preg_match('/\b[А-ЯЁЎҚҒҲа-яёўқғҳ]+\s+[А-ЯЁЎҚҒҲ]\.[А-ЯЁЎҚҒҲ]\./u', $value)) {
            $fail('Инициаллар ишлатиш тақиқланган. Тўлиқ исм ва отасининг исмини ёзинг.');

            return;
        }

        // Якка инициал ҳам тақиқланган: "А." каби
        if (preg_match('/\b[А-ЯЁЎҚҒҲ]\.\s/u', $value)) {
            $fail('Инициаллар ишлатиш тақиқланган. Тўлиқ исм ва отасининг исмини ёзинг.');
        }
    }
}
