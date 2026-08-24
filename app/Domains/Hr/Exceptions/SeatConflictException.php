<?php

declare(strict_types=1);

namespace App\Domains\Hr\Exceptions;

use App\Domains\Hr\Models\EventSeatAssignment;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * O'rindiq allaqachon band — assign paytida transaksiyani rollback qilish uchun
 * tashlanadi; controller uni tutib 409 shaklga aylantiradi.
 */
class SeatConflictException extends RuntimeException
{
    /** @param Collection<int, EventSeatAssignment> $occupied */
    public function __construct(public readonly Collection $occupied)
    {
        parent::__construct('Ba\'zi o\'rindiqlar allaqachon band.');
    }
}
