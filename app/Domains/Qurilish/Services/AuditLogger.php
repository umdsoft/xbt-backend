<?php

declare(strict_types=1);

namespace App\Domains\Qurilish\Services;

use App\Domains\Qurilish\Models\ConstructionObject;
use App\Domains\Qurilish\Models\ObjectAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Request;

/**
 * Obyekt o'zgarishlari jurnali (append-only).
 *
 * Nazorat organi (prokuratura) uchun «kim, qachon, nimani o'zgartirdi»
 * savolining javobi shu jadvalda. Shuning uchun jurnalga yozish servis
 * qatlamida majburiy, kontrollerda ixtiyoriy emas.
 */
class AuditLogger
{
    public function log(
        ConstructionObject $object,
        ?User $user,
        string $action,
        ?string $field = null,
        mixed $old = null,
        mixed $new = null,
    ): void {
        ObjectAuditLog::query()->create([
            'object_id' => $object->id,
            'user_id' => $user?->id,
            'action' => $action,
            'field' => $field,
            'old_value' => $this->scalar($old),
            'new_value' => $this->scalar($new),
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * O'zgargan maydonlarni solishtirib jurnalga yozadi.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function logChanges(ConstructionObject $object, ?User $user, array $before, array $after): void
    {
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? null;
            if ($this->scalar($old) === $this->scalar($value)) {
                continue;
            }
            $this->log($object, $user, 'update', $field, $old, $value);
        }
    }

    private function scalar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }

        return json_encode($v, JSON_UNESCAPED_UNICODE) ?: null;
    }
}
