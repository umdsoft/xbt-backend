<?php

declare(strict_types=1);

namespace App\Domains\Hr\Validators;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Қисқартиришларни рад этади.
 * TT бўлим 3.5.1: «Тош.», «вил.», «тум.», «ш.», «к.», «й.»
 */
class NoAbbreviationValidator implements ValidationRule
{
    /**
     * Тақиқланган қисқартиришлар regex паттернлари.
     *
     * @var array<string>
     */
    private const PATTERNS = [
        '/Тош\./u',
        '/вил\./u',
        '/тум\./u',
        '/\bш\./u',
        '/\bк\./u',
        '/\d{4}\s*й\./u',      // "1976 й." каби
        '/обл\./u',
        '/р-н\./u',
        '/г\.\s/u',            // "г. Ташкент" каби
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                $fail('Қисқартиришлар ишлатиш тақиқланган. Тўлиқ ёзинг (масалан: «Тошкент шаҳри» — «Тош.» эмас).');

                return;
            }
        }
    }
}
