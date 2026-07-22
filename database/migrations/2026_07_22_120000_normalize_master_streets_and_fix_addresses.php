<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Xorazm kadastr ko'cha (street) ma'lumotlarini normallashtirish.
 *
 * MUAMMO (rahbar aniqlagan): Ободонлаштириш matritsasida ko'chalarга biriktirilgan
 * uy soni noto'g'ri — ko'p ko'cha atigi 1 uyли, "UY_RAQAM" degan axlat ko'cha bор.
 *
 * ILDIZ: KMZ (Турар.kmz) importi 3 xil nuqsonni keltirgan:
 *   1) 1008 residential binoda `street='UY_RAQAM'` (label qiymat sifatida tushib qolган),
 *      lekin `address` (MANZIL) to'g'ri — "Ширинлар МФЙ ул. Наврўз 19".
 *   2) проезд/тупик/тор shoxobchalari alohida "ko'cha" sifatida (ул. Меҳроб, пр. 3),
 *      shu sabab bitta ko'cha 5-6 bo'lakка bo'linган.
 *   3) imlo variantlari (қ/к, ў/у, ғ/г, ҳ/х, ё/е): Мустақиллик vs Мустакиллик.
 *
 * YECHIM (forward-only, buzmaydigan):
 *   - UY_RAQAM binolarni address'дан tiklash (street + house_number).
 *   - Shoxobchalarni ASOSIY ko'chaга birlashtirish (norm_street_base).
 *   - Imlo variantlarini kanonik nomga keltirish (norm_street_key, eng ko'p uchragani).
 *   - master.streets ni qayta qurish, buildings/houses.street_id ni qayta bog'lash,
 *     yetim ko'chalarni o'chirish.
 *   - `address` O'ZGARMAYDI (проезд detali unда saqlanadi). Backup: master.buildings_addr_backup_v1.
 *
 * Tekshirilган (dev kbt, dry-run): 15 285 -> 12 251 ko'cha, UY_RAQAM 0 qoldi,
 * dangling FK 0, o'rtacha uy/ko'cha 24.4 -> 30.4. ОБОД МФЙ 148 -> 89 ko'cha.
 *
 * street_assignments = 0 qator (masъullar hali biriktirilmagan) -> birlashtirish xavfsiz.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
-- 1) BACKUP (rollback imkoni)
CREATE TABLE IF NOT EXISTS master.buildings_addr_backup_v1 (
  id uuid PRIMARY KEY, street varchar, house_number varchar, street_id uuid
);
INSERT INTO master.buildings_addr_backup_v1 (id, street, house_number, street_id)
SELECT id, street, house_number, street_id FROM master.buildings
ON CONFLICT (id) DO NOTHING;

-- 2) YORDAMCHI FUNKSIYALAR (doimiy — kelgusi importlar ham shu qoidada)
CREATE OR REPLACE FUNCTION master.norm_street_base(nm text) RETURNS text
LANGUAGE sql IMMUTABLE AS $fn$
  SELECT nullif(trim(regexp_replace(
    regexp_replace(split_part(nm, ',', 1),
      '\s+(\d+\s*тор|пр\.?\s*\d+|туп\.?\s*\d+|\d+\s*тор.*)\s*$', '', 'gi'),
    '\s+', ' ', 'g')), '');
$fn$;

CREATE OR REPLACE FUNCTION master.norm_street_key(nm text) RETURNS text
LANGUAGE sql IMMUTABLE AS $fn$
  SELECT regexp_replace(
    translate(
      translate(
        regexp_replace(coalesce(nm,''), '^\s*(ул\.|кўча|кучаси|кўч\.|кв\.)\s*', '', 'i'),
        'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯЎҚҒҲ',
        'абвгдеёжзийклмнопрстуфхцчшщъыьэюяўқғҳ'),
      'ўқғҳё', 'укгхе'),
    '[^a-zа-я0-9]', '', 'g');
$fn$;

CREATE INDEX IF NOT EXISTS streets_mahalla_name_idx ON master.streets (mahalla_id, name);
CREATE INDEX IF NOT EXISTS buildings_mahalla_street_idx ON master.buildings (mahalla_id, street);

-- 3) UY_RAQAM residential binolarni address'dan tiklash
UPDATE master.buildings b SET
  house_number = NULLIF(substring(x.aftr from '(\d+\S*)\s*$'), ''),
  street = CASE
    WHEN x.aftr ~ '^(р/с|рс|р\.с|РС|\.)\s*$' OR x.aftr = '' THEN NULL
    ELSE master.norm_street_base(trim(regexp_replace(x.aftr, '\s*\d+\S*\s*$', '')))
  END
FROM (
  SELECT id, regexp_replace(ltrim(regexp_replace(address, '^\s*'||coalesce(mahalla_name,''), '')), '\s+', ' ', 'g') AS aftr
  FROM master.buildings WHERE street = 'UY_RAQAM'
) x
WHERE b.id = x.id;

-- 4) KANONIK imlo xaritasi: (mahalla, fkey) -> eng ko'p uchragan base nom
CREATE TEMP TABLE _canon ON COMMIT DROP AS
WITH base AS (
  SELECT mahalla_id, master.norm_street_base(street) AS base_name, count(*) AS cnt
  FROM master.buildings
  WHERE street IS NOT NULL AND master.norm_street_base(street) IS NOT NULL
  GROUP BY mahalla_id, master.norm_street_base(street)
), ranked AS (
  SELECT mahalla_id, master.norm_street_key(base_name) AS fk, base_name,
    row_number() OVER (PARTITION BY mahalla_id, master.norm_street_key(base_name)
      ORDER BY cnt DESC, (base_name ~ '[қўғҳ]')::int DESC, base_name) AS rn
  FROM base
)
SELECT mahalla_id, fk, base_name AS canonical FROM ranked WHERE rn = 1;
CREATE INDEX ON _canon (mahalla_id, fk);

-- 5) Binolarga kanonik nomni qo'llash
UPDATE master.buildings b SET street = c.canonical
FROM _canon c
WHERE b.street IS NOT NULL
  AND b.mahalla_id = c.mahalla_id
  AND master.norm_street_key(master.norm_street_base(b.street)) = c.fk
  AND b.street IS DISTINCT FROM c.canonical;

-- 6) master.streets: har (mahalla, kanonik nom) uchun bitta qator (mavjudini qayta ishlat)
INSERT INTO master.streets (id, mahalla_id, name, is_active, sort_order, created_at, updated_at)
SELECT gen_random_uuid(), d.mahalla_id, d.street, true, 0, now(), now()
FROM (SELECT DISTINCT mahalla_id, street FROM master.buildings
      WHERE street IS NOT NULL AND mahalla_id IS NOT NULL) d
LEFT JOIN master.streets s ON s.mahalla_id = d.mahalla_id AND s.name = d.street
WHERE s.id IS NULL;

-- 7) buildings.street_id ni nomga ko'ra qayta bog'lash
UPDATE master.buildings b SET street_id = s.id
FROM master.streets s
WHERE b.street IS NOT NULL AND s.mahalla_id = b.mahalla_id AND s.name = b.street
  AND b.street_id IS DISTINCT FROM s.id;
UPDATE master.buildings SET street_id = NULL WHERE street IS NULL AND street_id IS NOT NULL;

-- 8) houses.street_id ni building orqali qayta bog'lash
UPDATE mahalla.houses h SET street_id = b.street_id
FROM master.buildings b WHERE h.building_id = b.id AND h.street_id IS DISTINCT FROM b.street_id;

-- 9) YETIM ko'chalarni o'chirish (bino/uy/biriktirish yo'q)
DELETE FROM master.streets s
WHERE NOT EXISTS (SELECT 1 FROM master.buildings b WHERE b.street_id = s.id)
  AND NOT EXISTS (SELECT 1 FROM mahalla.houses h WHERE h.street_id = s.id)
  AND NOT EXISTS (SELECT 1 FROM mahalla.street_assignments a WHERE a.street_id = s.id);

-- 10) sort_order ni alfavit bo'yicha (mahalla ichida)
UPDATE master.streets s SET sort_order = r.rn, is_active = true
FROM (SELECT id, row_number() OVER (PARTITION BY mahalla_id ORDER BY name) AS rn FROM master.streets) r
WHERE s.id = r.id;
SQL);
    }

    /**
     * Orqaga qaytarish: backup jadvaldan street/house_number/street_id ni tiklaydi.
     * (Yaratilgan yangi street qatorlari qoladi — ular zararsiz; asosiy ma'lumot tiklanadi.)
     */
    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
UPDATE master.buildings b SET
  street = k.street, house_number = k.house_number, street_id = k.street_id
FROM master.buildings_addr_backup_v1 k
WHERE b.id = k.id;
SQL);
    }
};
