<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Butun viloyat: "1-2 uyли ko'cha = soxta" konsolidatsiyasi (avtomatika qatlami).
 *
 * MUAMMO (rahbar): kadastrда ko'p "ko'cha" atigi 1-2 uyли — bular real ko'cha emas,
 * noto'g'ri yozilган yorliq. Shovotда 385 ta (37%) shunday; АРБЕК rasmiy ma'lumoti
 * ham buni tasdiqladi (7 haqiqiy ko'cha).
 *
 * YECHIM (koordinata):
 *   1) prefiks nom-variant birlashtirish (оилала->оилалар->оилалар2; RAQAMLI suffiks
 *      Хоразм-1..7 alohida qoladi; diff<=3 harf).
 *   2) <=2 uyли ko'cha binolari -> mahalla ичida eng yaqin >=10 uyли (haqiqiy) ko'chага
 *      (PostGIS KNN, geom SRID 4326).
 *   Rebuild master.streets, relink buildings/houses.street_id, yetim ko'cha o'chirish.
 *
 * Uy-darajасидaги aniq haqiqat (masalan Янги оилалар'ning fazoviy aralash uylari) bu
 * yerda TUZATILMAYDI — uni rais "Кўчаларни таҳрирлаш" asbobi bilan qo'lда kiritadi.
 *
 * Tekshirilган (dev kbt, dry-run): 12 250 -> 7 362 ko'cha, ≤2 uyли 10 qoldi (izolyatsiya),
 * o'rtacha 50.6 uy/ko'cha, dangling FK 0, over-merge 0. Backup: buildings_street_backup_v2.
 * street_assignments = 0 -> birlashtirish xavfsiz. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TABLE IF NOT EXISTS master.buildings_street_backup_v2 (
  id uuid PRIMARY KEY, street varchar, street_id uuid
);
INSERT INTO master.buildings_street_backup_v2 (id, street, street_id)
SELECT id, street, street_id FROM master.buildings ON CONFLICT (id) DO NOTHING;

CREATE TEMP TABLE bd ON COMMIT DROP AS
SELECT b.id, b.mahalla_id, b.street, b.geom, master.norm_street_key(b.street) AS k
FROM master.buildings b
WHERE b.type='residential' AND b.street IS NOT NULL AND b.mahalla_id IS NOT NULL AND b.geom IS NOT NULL;
CREATE TEMP TABLE ssz ON COMMIT DROP AS
SELECT mahalla_id, street, k, count(*) AS n FROM bd GROUP BY mahalla_id, street, k;
CREATE INDEX ON ssz (mahalla_id, k);

-- 1-BOSQICH: prefiks nom-variant (harfli suffiks, diff<=3, raqamli emas)
CREATE TEMP TABLE grp ON COMMIT DROP AS
SELECT a.mahalla_id, a.street, a.k, a.n,
  COALESCE((SELECT p.k FROM ssz p
    WHERE p.mahalla_id=a.mahalla_id AND length(p.k)>=4 AND a.k LIKE p.k||'%' AND p.k<>a.k
      AND length(a.k)-length(p.k) <= 3
      AND substring(a.k FROM length(p.k)+1) !~ '[0-9]'
    ORDER BY length(p.k) ASC, p.n DESC LIMIT 1), a.k) AS gk
FROM ssz a;
CREATE TEMP TABLE canon1 ON COMMIT DROP AS
SELECT DISTINCT ON (mahalla_id, gk) mahalla_id, gk, street AS cname
FROM (SELECT g.mahalla_id, g.gk, s.street, s.n, length(s.street) ln
      FROM grp g JOIN ssz s ON s.mahalla_id=g.mahalla_id AND s.k=g.k) z
ORDER BY mahalla_id, gk, n DESC, ln DESC;

CREATE TEMP TABLE b1 ON COMMIT DROP AS
SELECT bd.id, bd.mahalla_id, bd.geom, c.cname AS s1
FROM bd JOIN grp g ON g.mahalla_id=bd.mahalla_id AND g.street=bd.street
        JOIN canon1 c ON c.mahalla_id=bd.mahalla_id AND c.gk=g.gk;
CREATE INDEX ON b1 USING gist(geom);
CREATE INDEX ON b1 (mahalla_id, s1);
CREATE TEMP TABLE s1sz ON COMMIT DROP AS SELECT mahalla_id, s1, count(*) n FROM b1 GROUP BY mahalla_id, s1;

-- 2-BOSQICH: <=2 uyли ko'cha binolari -> eng yaqin >=10 uyли ko'cha (mahalla ичida)
CREATE TEMP TABLE finalmap ON COMMIT DROP AS
SELECT b1.id,
  CASE WHEN sz.n <= 2 THEN COALESCE((
     SELECT nb.s1 FROM b1 nb JOIN s1sz z ON z.mahalla_id=nb.mahalla_id AND z.s1=nb.s1
     WHERE nb.mahalla_id=b1.mahalla_id AND nb.id<>b1.id AND z.n>=10
     ORDER BY nb.geom <-> b1.geom LIMIT 1
  ), b1.s1) ELSE b1.s1 END AS s2
FROM b1 JOIN s1sz sz ON sz.mahalla_id=b1.mahalla_id AND sz.s1=b1.s1;

UPDATE master.buildings b SET street=f.s2 FROM finalmap f WHERE b.id=f.id AND b.street IS DISTINCT FROM f.s2;

INSERT INTO master.streets (id, mahalla_id, name, is_active, sort_order, created_at, updated_at)
SELECT gen_random_uuid(), d.mahalla_id, d.street, true, 0, now(), now()
FROM (SELECT DISTINCT mahalla_id, street FROM master.buildings WHERE street IS NOT NULL AND mahalla_id IS NOT NULL) d
LEFT JOIN master.streets s ON s.mahalla_id=d.mahalla_id AND s.name=d.street
WHERE s.id IS NULL;
UPDATE master.buildings b SET street_id=s.id
FROM master.streets s
WHERE b.street IS NOT NULL AND s.mahalla_id=b.mahalla_id AND s.name=b.street AND b.street_id IS DISTINCT FROM s.id;
UPDATE mahalla.houses h SET street_id=b.street_id
FROM master.buildings b WHERE h.building_id=b.id AND h.street_id IS DISTINCT FROM b.street_id;
DELETE FROM master.streets s
WHERE NOT EXISTS (SELECT 1 FROM master.buildings b WHERE b.street_id=s.id)
  AND NOT EXISTS (SELECT 1 FROM mahalla.houses h WHERE h.street_id=s.id)
  AND NOT EXISTS (SELECT 1 FROM mahalla.street_assignments a WHERE a.street_id=s.id);
UPDATE master.streets s SET sort_order=r.rn, is_active=true
FROM (SELECT id, row_number() OVER (PARTITION BY mahalla_id ORDER BY name) rn FROM master.streets) r
WHERE s.id=r.id;
SQL);
    }

    /** Backup'дан street/street_id ni tiklaydi (yaratilган yangi street qatorlari zararsiz qoladi). */
    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
UPDATE master.buildings b SET street=k.street, street_id=k.street_id
FROM master.buildings_street_backup_v2 k WHERE b.id=k.id;
SQL);
    }
};
