<?php

declare(strict_types=1);

namespace App\Domains\Ayollar\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * OFLAYN NAVBATDAN KELGAN HAVOLANI HAL QILADI.
 *
 * Planshet tarmoqsiz ishlaydi va yozuvlarni O'ZI yaratadi: xonadonga,
 * ayolga va anketaga darhol UUID beradi, chunki ular bir-biriga shu
 * zahoti bog'lanishi kerak. Server esa yozuvni qabul qilganda O'Z
 * identifikatorini beradi va klientnikini `client_uuid` ustuniga yozadi.
 *
 * NUQSON SHU YERDA EDI. Navbat tartibi to'g'ri — xonadon, keyin ayol —
 * lekin ayol `household_id` sifatida KLIENTNING uuid'sini yuboradi.
 * Server esa uni `id` bo'yicha qidirardi va topa olmasdi:
 *
 *     households.id          = 01a09f87-b59e-…  (server bergan)
 *     households.client_uuid = a938be66-132d-…  (klientniki, ayol shuni yuboradi)
 *
 * Natijada 2026-09-14 da xonadonlar bazaga tushdi, ayollar esa
 * BITTASI HAM tushmadi — har biri 404 bilan qaytdi. Anketa ayolga
 * bog'langani uchun u ham tushmadi. Planshetda «saqlandi» deb ko'rinar,
 * bazada esa hech narsa yo'q edi.
 *
 * Yechim: havola IKKALA ustun bo'yicha ham qidiriladi. Klient server
 * bergan identifikatorni BILMAYDI va bilishi ham shart emas — oflayn
 * tizimda korrelyatsiya identifikatorini klient beradi, server esa uni
 * taniydi.
 */
trait ResolvesClientRef
{
    /**
     * Yozuvni server `id` yoki klient `client_uuid` bo'yicha topadi.
     *
     * Ikkalasi ham tasodifiy UUID bo'lgani uchun to'qnashuv amalda
     * bo'lmaydi; qidiruv qavs ichida, chunki `orWhere` tashqaridagi
     * doira shartlarini (MFY, tuman) buzib yuborardi.
     */
    public function scopeByClientRef(Builder $query, string $ref): Builder
    {
        return $query->where(
            fn (Builder $inner) => $inner->where('id', $ref)->orWhere('client_uuid', $ref),
        );
    }
}
