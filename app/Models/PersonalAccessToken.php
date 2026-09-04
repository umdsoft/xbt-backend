<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum tokeni — `auth` ulanishida.
 *
 * NEGA KERAK: token `User` modelining `morphMany` munosabati orqali YOZILADI,
 * ya'ni `auth` ulanishi bilan. Sanctum'ning standart modeli esa ulanishni
 * belgilamaydi va default (`pgsql`) orqali O'QIYDI. Jadval jismonan bitta
 * (`public.personal_access_tokens`) — ikkala search_path ham unga tushadi,
 * shuning uchun ishlab chiqarishda farq sezilmaydi.
 *
 * Ammo bu ikki ulanish — ikki alohida PDO seansi. Testlar `DatabaseTransactions`
 * bilan ishlaganda `auth` tranzaksiyasidagi yozuv `pgsql` tranzaksiyasiga
 * KO'RINMAYDI, natijada mobil token bilan har so'rov 401 qaytaradi. Ya'ni
 * mobil auth oqimini umuman test qilib bo'lmasdi.
 *
 * Ulanishni qadab qo'yish yozish va o'qishni bitta seansga keltiradi:
 * testlar haqiqiy oqimni tekshiradi, ishlab chiqarishdagi xulq o'zgarmaydi.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $connection = 'auth';
}
