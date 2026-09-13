# Mahalla mobil — «Атроф» (jonli radius xarita) moduli — DIZAYN

> Sana: 2026-09-13 · Pilot: **Shovot tumani** (SOATO `1733230`, 52 mahalla, ~34 651 turar-joy + tashkilotlar)
> Repozitoriylar: backend `D:\kadr\platform` (Laravel + PostGIS) · mobil `D:\kadr\mahalla_mobile` (Flutter)
> Holat: **DIZAYN — tasdiqlash kutilmoqda** (implementatsiya boshlanmagan)

## 1. Maqsad (rahbar topshirig'i)

Deputat mahallada/ko'chada yurganda **real vaqtda** o'zi turgan joydan **N-km radius** (default **3 km**, sozlanuvchi) ichidagi **binolar va tashkilotlar** xaritada ko'rinib borishi, hamda **o'zi turgan mahalla** haqida ma'lumot (nom + qisqa statistika + chegara) chiqib turishi kerak. Yurgan sari xarita va nuqtalar yangilanadi.

**Tasdiqlangan yo'nalish (foydalanuvchi qarori):**
- **Asosiy vazifa:** monitoring + ma'lumot (ikkalasi). Monitoring binolari holati (rang) bilan ko'rinadi, bosilsa → 4-zona detali/surat olishga o'tadi. Boshqa binolar/tashkilotlar bosilsa → ma'lumot kartasi.
- **Offline strategiya:** **Gibrid** — online OSM tayl + ko'rilgan hudud tayllarini diskka avto-keshlash (signalsiz joyda ham ko'rilgan hudud fon bilan ko'rinadi). Ma'lumot (nuqtalar) ham keshlanadi.
- **Ko'rinish:** **Qatlamli** — doim: monitoring binolari (rang) + muhim tashkilotlar (maktab/shifoxona/masjid/MFY); qolgan uy-joylar zoom/klaster bilan; tepada qatlam tugmalari.

## 2. Arxitektura yondashuvi (ko'rib chiqilgan variantlar)

**A — Server-side geo-radius endpoint (ST_DWithin) — TANLANDI.** Backend `master.buildings.geom` (PostGIS Point, GIST-indeks) bo'yicha `ST_DWithin` radius so'rovi qiladi, `object_types` bilan tasniflaydi, monitoring holatini `mahalla.houses` bilan bog'laydi, `ST_Contains` bilan joriy mahallani aniqlaydi. **Sabab:** faqat shu yondashuv radiusdagi **BARCHA** bino/tashkilotni (nafaqat deputatga biriktirilganini) ko'rsata oladi — bu aynan topshiriq talabi. PostGIS tayyor, schema o'zgarmaydi.

**B — Faqat klient (worklist'ni GPS bo'yicha filtrlash) — RAD ETILDI.** Yangi backend shart emas, lekin `GET /worklist` faqat deputatning *biriktirilgan* binolarini beradi — radiusdagi tashkilotlar va boshqa uylar KO'RINMAYDI. Topshiriqni bajarmaydi.

**C — Gibrid (o'z monitoringi klientdan + qolgani serverdan) — KERAK EMAS.** Ortiqcha murakkablik; A varianti monitoring holatini ham bitta so'rovda qaytara oladi (kross-schema join), shuning uchun bo'linishning hojati yo'q (YAGNI).

## 3. Backend dizayni (`D:\kadr\platform`)

### 3.1 Yangi endpoint'lar

Yangi controller: `App\Domains\Mahalla\Http\Controllers\Api\Deputat\NearbyController` + yangi servis `App\Domains\Mahalla\Services\NearbyFinder`. Marshrutlar `routes/api/mahalla.php` ichida, `auth:sanctum` + `system.access:mahalla` guardida.

**(1) `GET /api/mahalla/nearby`** — radiusdagi nuqtalar (tez-tez chaqiriladi).
Query params (validatsiya majburiy):
- `lat` (float, -90..90), `lng` (float, -180..180) — MAJBURIY.
- `radius_m` (int, 200..5000, default 3000) — server MAX 5000 bilan cheklaydi (himoya).
- `layers` (csv: `monitoring,homes,orgs`; default `monitoring,orgs`).
- `limit` (int, default 600, max 1500).

Javob (plain JSON, GeoJSON emas — mavjud `rais/map` konvensiyasi):
```json
{
  "center": {"lat": 41.55, "lng": 60.63},
  "radius_m": 3000,
  "current_mahalla": {
    "id": "<uuid>", "name": "Тупроққалъа МФЙ",
    "stats": {"buildings": 82, "monitored": 12, "completed": 3}
  },
  "counts": {"monitoring": 40, "homes": 380, "orgs": 22, "returned": 442, "truncated": false},
  "points": [
    {
      "id": "<uuid>", "lat": 41.55, "lng": 60.63, "distance_m": 120,
      "kind": "monitoring",              // monitoring | home | org
      "type": "residential",             // residential | non_residential
      "is_social": false,
      "category": null,                  // org bo'lsa: 'school'|'clinic'|'mosque'|'mfy'|...
      "category_label": null,            // "Мактаб" (Kiril)
      "address": "ул. Ишонч, пр. 2, 1",
      "kadastr": "22:06:05:01:07:1509",
      "mahalla": "Тупроққалъа МФЙ",
      "mine": true,                      // deputatga biriktirilgan (street_id scope)
      "monitored": true,
      "overall_status": "in_progress"    // monitored bo'lsa
    }
  ]
}
```

**(2) `GET /api/mahalla/mahallas/{mahalla}/boundary`** — joriy mahalla chegarasi (GeoJSON), FAQAT mahalla `id` o'zgarganda olinadi. `ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0003))` (~33m tolerance) + `ExecutiveCache::remember` bilan keshlanadi (mavjud `DistrictGeoJsonController` patterni). Bu og'ir geometriya har GPS tickda qayta yuborilmasin uchun alohida.

### 3.2 Asosiy so'rov (NearbyFinder)

Bitta SQL — `mahalla` ulanishida (search_path `mahalla,master,public` → ikkala schemani ko'radi), performant PostGIS pattern:
```sql
-- bbox (&&) GIST indeksini ishlatadi, ST_DWithin aniqlashtiradi (geography = metr)
SELECT b.id, b.lat, b.lng, b.type, b.address, b.kadastr, b.mahalla_name,
       b.street_id, ot.code AS category, ot.is_social,
       ST_Distance(b.geom::geography, pt.g) AS distance_m,
       h.status AS overall_status, (h.id IS NOT NULL) AS monitored
FROM (SELECT ST_SetSRID(ST_MakePoint(:lng,:lat),4326)::geography AS g,
             ST_SetSRID(ST_MakePoint(:lng,:lat),4326) AS gp) pt,
     master.buildings b
LEFT JOIN master.object_types ot ON ot.id = b.object_type_id
LEFT JOIN mahalla.houses h ON h.building_id = b.id
WHERE b.geom && ST_Expand(pt.gp, :deg)              -- bbox pre-filter (GIST)
  AND ST_DWithin(b.geom::geography, pt.g, :radius_m) -- aniq metr radius
  AND ( :want_homes OR b.type <> 'residential' )     -- layer filtrlar
  AND ( :want_orgs  OR b.type =  'residential' )
ORDER BY distance_m
LIMIT :limit;
```
- `kind` hisoblash (PHP): `monitored → 'monitoring'`; `type='non_residential' → 'org'`; else `'home'`.
- `mine` = `street_id ∈ deputat scope` (mavjud `MahallaAccess` scope).
- Joriy mahalla: `SELECT id, name_cyr FROM master.mahallas WHERE district_id = :d AND ST_Contains(boundary, :gp) LIMIT 1` (GIST-indeks; `:d` = deputat district yoki pilotda Shovot).
- `stats`: mavjud aggregatsiya (worklist/executive) shu mahalla uchun.

### 3.2.1 O'LCHANGAN REALLIK (local `kbt` DB, PostGIS 3.6.2, 2026-09-13)

Shovot tumani (`districts.soato_code='1733230'`, id `3b608e0b-c1c9-340f-9bcb-9aeec2671fff`):
**34 651 residential + 1 617 non_residential**. Butun baza: 405 464 bino.

Zich nuqtada (РОЯТ МФЙ, `41.674814, 60.248507`) radius bo'yicha nuqta soni:

| Radius | residential | non_residential | Jami |
|---|---|---|---|
| 500 m | 350 | 10 | **360** |
| 1 km | 788 | 23 | **811** |
| **3 km** | **4 190** | **244** | **4 434** |

**Tezlik:** `b.geom && ST_Expand(...)` + `ST_DWithin(geom::geography,...)` + `ORDER BY distance LIMIT 600`
→ `Bitmap Index Scan on buildings_geom_gix`, **Execution 23.7 ms** (Planning 6.9 ms).

**Xulosa:** so'rov TEZ; muammo **zichlik** — 3 km da ~4.4k nuqta telefon uchun ko'p.
Shuning uchun: qatlamli yuklash + qattiq `LIMIT` + klaster MAJBURIY (dizaynga kiritilgan).
`homes` qatlami 3 km da eng og'iri (4 190) → default OFF, yoqilganda limit + klaster + zoom-gate.
`orgs` (244) va `monitoring` (deputatga biriktirilgani — kichik) yengil, default ON bo'lishi xavfsiz.

### 3.2.2 Shovot object_types (real, Kiril yorliqlari bilan)

`is_social = true` (muhim tashkilotlar, 3 km da odatda o'nlab):
`mamuriy` Маъмурий бино (34) · `dorixona` Дорихона (18) · `maktab` Мактаб (15) ·
`qvp` Қишлоқ врачлик пункти (14) · `mfy_binosi` МФЙ биноси (11) · `bogcha` Болалар боғчаси (9) ·
`madaniyat` Маданият муассасаси (6) · `shifoxona` Шифохона/касалхона (3) · `diniy` Диний объект (2) · `sport` Спорт объекти (1)

`is_social = false`: `boshqa` (588) · `savdo` Савдо объекти (361) · `yer_uchastka` (206) ·
`maishiy` (114) · `ishlab_chiqarish` (91) · `ombor` (44) · `qurilmagan` (41) · `infratuzilma` (33) · `chorva` (26)

`object_types` ustunlari: `id, code, name_cyr, name_lat, keywords, is_social, is_building, sort_order`.
UI yorliqlari uchun **`name_cyr`** ishlatiladi (hardcode QILINMAYDI — serverdan keladi).

### 3.3 Performance himoyalari
- GIST `buildings_geom_gix` + `&&` bbox + `ST_DWithin(geography)` — indeksdan foydalanadi.
- `district_id` bo'yicha old-filtr (indeksli) — planner uchun.
- Qattiq `LIMIT` (default 600, max 1500), `ORDER BY distance` — eng yaqinlar. `truncated` bayrog'i.
- Chegara GeoJSON alohida + keshlangan (`ST_SimplifyPreserveTopology`).
- `throttle` (masalan `throttle:60,1`) — tez-tez chaqiriladigan endpoint.

### 3.4 Xavfsizlik / maxfiylik
- `auth:sanctum` + `system.access:mahalla`. `lat/lng/radius` validatsiya + radius MAX-cap.
- `buildings` jadvalida rezident ismi/PII YO'Q (faqat kadastr/manzil) — deputat worklistda ham ko'radigan daraja. Monitoring **harakati** (surat) faqat biriktirilgan binoda.
- **Qamrov (TASDIQLANGAN): tuman ichi — hammasi.** Radiusdagi BARCHA bino/tashkilot (biriktirilgan/biriktirilmagan) cadastre-darajasida (manzil/kadastr) ko'rinadi. Monitoring **harakati** (surat) faqat biriktirilgan binoda. Deputat district'i bilan cheklanadi (pilotda Shovot).
- Endpoint so'rov narxini monitoring (log).

### 3.5 Testlar (backend, Pest/PHPUnit)
- `ST_DWithin` radius: markazdan ichkarida/tashqarida bino to'g'ri filtrlanadi (Shovot koordinatalari bilan).
- `layers` filtr: `homes`/`orgs` to'g'ri qaytadi.
- `limit`/`truncated` cap ishlaydi.
- `current_mahalla`: nuqta mahalla ichida → to'g'ri mahalla; chegaradan tashqarida → null.
- `mine`/`monitored` join to'g'ri.
- Auth: token'siz 401; radius > 5000 → 422.

## 4. Mobil dizayn (`D:\kadr\mahalla_mobile`)

### 4.1 Paketlar (yangi)
- `flutter_map` (OSM tayl; Google Maps EMAS — nativ SDK/billing yo'q, offline-kesh dala uchun mos).
- `flutter_map_tile_caching` (FMTC) — gibrid offline tayl keshi (ko'rilgan hudud diskda).
- `flutter_map_marker_cluster` — klaster (zichlikda).
- `latlong2` (flutter_map bog'liqligi).
- Versiyalar implementatsiyada Flutter 3.44/Dart 3.12 ga mos pinlanadi (build-resolver bilan).

### 4.2 Yangi struktura
```
lib/core/geo/location_provider.dart   // YANGI — jonli GPS stream (capture'dan ajratilgan, DRY)
lib/features/map/
  models.dart              // NearbyPoint, NearbyData, CurrentMahalla (BuildingSummary'ga yaqin)
  nearby_repository.dart   // GET /nearby + /boundary; LocalCache fallback (.asStale)
  nearby_controller.dart   // AsyncNotifier — GPS stream × radius × layers → nearby qayta so'rov
  map_screen.dart          // flutter_map + qatlamlar + follow-me + radius doira + tap sheet
  radius_prefs.dart        // sozlanuvchi radius (LocalCache'da saqlanadi, default 3000)
```

### 4.3 Navigatsiya
`home_shell.dart` — 3-tab qo'shiladi: **«Атроф»** (icon `Icons.explore`/`map`). `IndexedStack` + `NavigationDestination`. `auth_gate.dart` o'zgarmaydi.

### 4.4 Jonli lokatsiya (reused pattern)
`capture_screen.dart` dagi geolocator ketma-ketligi umumiy `location_provider.dart` ga chiqariladi (service-check → permission → `getPositionStream` → xato/timeout). Xarita uchun `distanceFilter ~12m` (yurish uchun; batareya tejaydi), `LocationAccuracy.high`. **Foreground-only** (mavjud ruxsatlar yetarli — background permission yo'q). Tab yopilganda stream to'xtaydi.

### 4.5 Qayta so'rov strategiyasi
- Foydalanuvchi oxirgi so'rov markazidan **> radius×0.25** (masalan 750m) siljisa → yangi `/nearby`. Debounce (masalan 2–3s).
- Radius yoki layer o'zgarsa → darhol qayta so'rov.
- Boundary FAQAT `current_mahalla.id` o'zgarganda olinadi.

### 4.6 Xarita UI
- **Follow-me** rejim (GPS bo'yicha avto-markaz) + «Мен» (recenter) tugmasi. Erkin pan qilsa follow-me o'chadi, tugma qayta yoqadi.
- **Radius doira** (GPS atrofida, joriy radiusda).
- **Qatlam chiplari** (tepada): `Мониторинг` (mine, default ON) · `Ташкилотлар` (is_social/org, default ON) · `Уйлар` (boshqa turar-joy, default OFF). Bosilsa layer qayta so'raladi/ko'rsatiladi.
- **Markerlar:**
  - Monitoring (mine): rang = `overall_status` (`not_started`→qizil, `in_progress`→sariq, `completed`→yashil) — mavjud `zoneStatusColor` mantig'i.
  - Org: kategoriya ikoni (maktab/shifoxona/masjid/MFY/...), is_social ajralib turadi.
  - Home (boshqa): kichik neytral nuqta.
  - Zichlikda **klaster** (`flutter_map_marker_cluster`).
- **Tepada banner:** «Сиз ҳозир **{mahalla}** да» + qisqa stat (bino/monitored/tayyor). Mahalla chegarasi xaritada dashed outline.
- **Marker bosilsa → pastki sheet:**
  - Monitoring bino → holat + «Зона детали / Сурат олиш» (→ mavjud `HouseScreen(buildingId)`).
  - Boshqa bino/org → ma'lumot kartasi (manzil, kadastr, tur/kategoriya, mahalla).

### 4.7 Radius sozlash
Slider/stepper (1–5 km, qadam 0.5), default 3 km, `LocalCache` da saqlanadi (`{"radius_m":3000}`). `const` emas — runtime mutable (Riverpod state).

### 4.8 Offline (gibrid)
- **Tayl:** FMTC online tayllarni diskka keshlaydi → ko'rilgan hudud signalsiz fon bilan.
- **Ma'lumot:** oxirgi `/nearby` javobi (markaz/mahalla bo'yicha) `LocalCache`da; tarmoq xatosida `.asStale()` + stale banner (mavjud pattern).
- Tayl keshi = paket vazifasi; ma'lumot keshi = `LocalCache` — aralashtirilmaydi.

## 5. Ma'lumotlar oqimi
```
GPS tick → nearby_controller (debounce, >750m siljish?)
   → nearby_repository.getNearby(lat,lng,radius,layers)
        online → GET /api/mahalla/nearby → LocalCache.save → NearbyData
        offline → LocalCache.load → NearbyData.asStale()
   → map_screen: markerlar (qatlam bo'yicha) + radius doira + "siz {mahalla}dasiz"
   → mahalla.id o'zgardi? → getBoundary(id) (keshlangan) → outline
   → marker tap → monitoring? HouseScreen : info sheet
```

## 6. Bosqichlar (milestones)
1. **Backend nearby + current-mahalla + boundary + testlar** (Shovot bilan). Deploy (queue emas — web endpoint, fpm reload).
2. **Mobil poydevor:** `flutter_map` qo'shish, «Атроф» tab, `location_provider`, radius doira, follow-me, **monitoring qatlami** + tap→HouseScreen.
3. **Qatlamlar:** org + home qatlamlari, klaster, qatlam chiplari, info sheet, «siz {mahalla}dasiz» + boundary outline.
4. **Offline + config:** FMTC gibrid tayl kesh, ma'lumot keshi + stale, radius sozlash (persist).
5. **Real telefon dala sinovi (Shovot):** zichlik/limit/batareya/qayta-so'rov tuningi; deploy.

## 7. Risklar va ochiq savollar
- **Tayl zichligi/OSM fair-use:** ommaviy OSM tayl pilotda ok; keng miqyosda o'z tayl-serveri kerak bo'lishi mumkin (kelajak).
- **`geom::geography` narxi:** 34k/mahalla miqyosida bbox+GIST bilan ok; viloyat miqyosi kengaysa `geography` generated ustun/indeks ko'rilsin.
- **Batareya:** uzoq yurishda GPS+xarita — `distanceFilter`/qayta-so'rov debounce bilan boshqariladi; sinovda o'lchash.
- **HAL QILINDI:** «boshqa uylar» qamrovi = **tuman ichi to'liq** (biriktirilmagan turar-joy ham radiusda cadastre-darajasida ko'rinadi); monitoring harakati faqat biriktirilganda.
- **Ochiq (implementatsiyada):** klaster/FMTC paketlari Flutter 3.44 / Dart 3.12 mosligini pinlash (dart-build-resolver bilan).

## 8. Qabul mezonlari (Definition of Done)
- Deputat «Атроф» tabda o'z joyi atrofida radius doira + yaqin nuqtalarni ko'radi; yurgan sari yangilanadi.
- Radius 1–5 km sozlanadi va saqlanadi (default 3 km).
- Monitoring binolari holati rang bilan; bosilsa zona detali/surat oladi.
- Tashkilot/boshqa bino bosilsa ma'lumot kartasi.
- «Сиз {mahalla}дасиз» + mahalla chegarasi + qisqa stat.
- Qatlam tugmalari ishlaydi; zichlikda klaster.
- Signalsiz: ko'rilgan hudud tayl + oxirgi ma'lumot (stale banner) ko'rinadi.
- Backend `ST_DWithin`/`ST_Contains` testlari yashil; `flutter analyze` toza; release APK quriladi.
- Shovot'da real telefonda sinovdan o'tadi.
