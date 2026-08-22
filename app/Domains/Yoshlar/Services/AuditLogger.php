<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Audit jurnali — hukumat hisobdorligi uchun. PII QIYMATLARI yozilmaydi:
 * jurnal o'zi maxfiy ma'lumot omboriga aylanib qolmasligi kerak.
 */
class AuditLogger
{
    /** @param array<string, mixed>|null $changes */
    public function log(User $user, string $action, string $entityType, ?string $entityId, ?array $changes = null): void
    {
        DB::connection('yoshlar')->table('audit_log')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
