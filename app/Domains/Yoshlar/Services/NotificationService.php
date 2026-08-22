<?php

declare(strict_types=1);

namespace App\Domains\Yoshlar\Services;

use App\Domains\Yoshlar\Models\Notification;
use App\Domains\Yoshlar\Models\Organization;
use App\Domains\Yoshlar\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * In-app bildirishnomalar (TZ 5.8).
 *
 * KIMGA YUBORILADI: aniq odamga emas, TASHKILOTGA — o'sha tashkilotning
 * barcha faol xodimlariga. Sabab: zanjirdagi navbat shaxsga emas,
 * tashkilotga tegishli; xodim ta'tilda bo'lsa ish to'xtab qolmasin.
 *
 * TAKRORLANMASLIK: `(user_id, type, entity_id)` bo'yicha o'qilmagan yozuv
 * bo'lsa yangisi yaratilmaydi (DB'da partial unique). Kunlik muddat
 * tekshiruvi har ishga tushganda bir xil xabarni takrorlamaydi.
 */
class NotificationService
{
    /**
     * Tashkilot xodimlariga bildirishnoma yozadi.
     *
     * @param  array<string, mixed>  $payload  title, body, link, entity_type, entity_id
     * @return int  yaratilgan yozuvlar soni
     */
    public function notifyOrganization(string $orgId, string $type, array $payload): int
    {
        $userIds = Staff::query()
            ->where('org_id', $orgId)
            ->where('is_active', true)
            ->pluck('user_id')
            ->all();

        return $this->notifyUsers($userIds, $type, $payload);
    }

    /**
     * Rolga qarab yuborish — masalan yakuniy tasdiq bosqichi viloyat
     * yoshlar boshqarmasiga tegishli bo'lganda.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifyRole(string $role, string $type, array $payload): int
    {
        $userIds = DB::connection('auth')->table('user_system_access as usa')
            ->join('systems as s', 's.id', '=', 'usa.system_id')
            ->where('s.code', 'yoshlar')
            ->where('usa.role', $role)
            ->where('usa.is_active', true)
            ->pluck('usa.user_id')
            ->all();

        return $this->notifyUsers($userIds, $type, $payload);
    }

    /**
     * Sektor kodiga qarab yuborish (masalan soliq organiga) — F3 zanjiri
     * rolga emas, rol + sektor juftligiga bog'langani uchun kerak.
     *
     * @param  array<string, mixed>  $payload
     */
    public function notifySector(string $sectorCode, string $orgType, ?string $districtId, string $type, array $payload): int
    {
        $orgIds = Organization::query()
            ->where('type', $orgType)
            ->where('is_active', true)
            ->whereIn('sector_id', function ($q) use ($sectorCode) {
                $q->select('id')->from('yoshlar.sectors')->where('code', $sectorCode);
            })
            ->when($districtId !== null, fn ($q) => $q->where('district_id', $districtId))
            ->pluck('id')
            ->all();

        $count = 0;

        foreach ($orgIds as $orgId) {
            $count += $this->notifyOrganization((string) $orgId, $type, $payload);
        }

        return $count;
    }

    /**
     * @param  array<int, string>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function notifyUsers(array $userIds, string $type, array $payload): int
    {
        $count = 0;

        foreach ($userIds as $userId) {
            // Takroriy o'qilmagan yozuv bo'lsa — o'tkazib yuboramiz.
            $exists = Notification::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->where('entity_id', $payload['entity_id'] ?? null)
                ->whereNull('read_at')
                ->exists();

            if ($exists) {
                continue;
            }

            Notification::query()->create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $payload['title'],
                'body' => $payload['body'] ?? null,
                'link' => $payload['link'] ?? null,
                'entity_type' => $payload['entity_type'] ?? null,
                'entity_id' => $payload['entity_id'] ?? null,
            ]);

            $count++;
        }

        return $count;
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()->where('user_id', $user->id)->unread()->count();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Notification> */
    public function listFor(User $user, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function markRead(User $user, ?string $id = null): int
    {
        $query = Notification::query()->where('user_id', $user->id)->unread();

        if ($id !== null) {
            $query->whereKey($id);
        }

        return $query->update(['read_at' => now()]);
    }
}
