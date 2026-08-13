<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Administrator amallarini yozadi (hisob, dastur, soha, tashkilot).
 *
 * Parol HECH QACHON jurnalga tushmaydi — hatto hash ko'rinishida ham.
 * `changes` da faqat qaysi maydonlar o'zgargani va yangi qiymatlari bo'ladi;
 * maxfiy maydonlar `REDACTED` bilan almashtiriladi.
 */
class AdminAuditLogger
{
    /** Jurnalda hech qachon ko'rinmaydigan maydonlar. */
    private const SECRET = ['password', 'password_confirmation', 'token'];

    public function __construct(private readonly Request $request) {}

    /** @param array<string, mixed>|null $changes */
    public function log(
        User $actor,
        string $action,
        string $entity,
        ?string $entityId,
        ?string $label = null,
        ?array $changes = null,
    ): void {
        AdminAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'entity_label' => $label,
            'changes' => $changes === null ? null : $this->redact($changes),
            'ip' => $this->request->ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        foreach (self::SECRET as $key) {
            if (array_key_exists($key, $changes)) {
                $changes[$key] = 'REDACTED';
            }
        }

        return $changes;
    }
}
