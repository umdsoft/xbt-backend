<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Http\Controllers\Api;

use App\Domains\Qurilish\Support\QurilishAccess;
use App\Http\Controllers\Controller;
use App\Models\User;

/**
 * Domen kontrollerlari uchun umumiy poydevor: ruxsat darvozasi.
 *
 * `EnsureQurilish` middleware faqat «tizimda bormi» ni tekshiradi; aniq
 * amalga ruxsat esa har kontrollerda. `authorize()` ni unutish oson bo'lgani
 * uchun u bitta joyda va nomi qisqa.
 */
abstract class QurilishController extends Controller
{
    public function __construct(protected readonly QurilishAccess $access) {}

    /** Ruxsat yo'q bo'lsa 403 bilan to'xtatadi. */
    protected function authorizeAction(User $user, string $permission): void
    {
        if (! $this->access->can($user, $permission)) {
            abort(403, 'Бу амал учун рухсат йўқ.');
        }
    }
}
