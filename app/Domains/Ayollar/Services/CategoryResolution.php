<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Services;

/**
 * `CategoryResolver` natijasi — o'zgarmas qiymat obyekti.
 *
 * `trace` shunchaki nosozlik tuzatish uchun emas: veb kartochkadagi «Toifa
 * qanday aniqlandi» bloki (promt §10.4) aynan shundan chiziladi. Ya'ni qaror
 * FOYDALANUVCHIGA ko'rinadi — qaysi shart ishladi, qaysilari o'tkazib
 * yuborildi. Avtomatik toifalash tizimida bu majburiy: raqam bilan
 * rozilashmagan odam sababini ko'ra olishi kerak.
 */
final class CategoryResolution
{
    public const GREEN = 'green';

    public const YELLOW = 'yellow';

    public const INCOMPLETE = 'incomplete';

    /**
     * @param  array<int, array<string, mixed>>  $trace
     * @param  array<int, array{code: string, source_question: int}>  $redFlags
     */
    public function __construct(
        public readonly string $category,
        public readonly ?string $balanceRow,
        public readonly ?int $step,
        public readonly array $trace,
        public readonly array $redFlags,
        public readonly string $rulesVersion,
    ) {}

    public function isIncomplete(): bool
    {
        return $this->category === self::INCOMPLETE;
    }

    public function isGreen(): bool
    {
        return $this->category === self::GREEN;
    }

    public function isYellow(): bool
    {
        return $this->category === self::YELLOW;
    }

    /** @return array<int, string> */
    public function redFlagCodes(): array
    {
        return array_column($this->redFlags, 'code');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'balance_row' => $this->balanceRow,
            'step' => $this->step,
            'trace' => $this->trace,
            'red_flags' => $this->redFlags,
            'rules_version' => $this->rulesVersion,
        ];
    }
}
