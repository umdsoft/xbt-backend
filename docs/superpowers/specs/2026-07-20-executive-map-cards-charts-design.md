# Rahbariyat dashboard'i — xarita, KPI cardlar va mahalla statistikasi

**Sana:** 2026-07-20
**Holat:** tasdiqlangan
**Asos:** `2026-07-20-mahalla-executive-dashboard-design.md` (birinchi bosqich, bajarilgan)
**Qamrov:** `platform` (backend) + `mahalla` (Vue SPA)

## 1. Muammo

Birinchi bosqichda ikki darajali jadval qurildi. Rahbar undan raqamni ko'radi, lekin:
- **qayerda** o'zgarish bo'layotganini fazoviy tasavvur qila olmaydi (52 qatorli ro'yxatdan hudud tasavvuri chiqmaydi);
- umumiy holatni bilish uchun 52 qatorni ko'zdan kechirishi kerak;
- bitta mahallaga kirganda faqat 4 qatorli statik jadval bor — ish jadal ketyaptimi yoki to'xtaganmi bilib bo'lmaydi.

## 2. Maqsad va chegara

Uch qo'shimcha: **xarita**, **4 ta KPI card**, **mahalla sahifasida uchta statistika bloki**.

**Bu ishga KIRMAYDI (YAGNI):**
- Xaritada xonadon darajasidagi nuqtalar (52 poligon yetarli; 34 648 nuqta foydasiz)
- Xaritada vaqt bo'yicha animatsiya
- Deputat faolligi bloki (foydalanuvchi tanlovidan chiqarildi)
- Eksport, chop etish
- Boshqa tumanlar (kod tayyor, hozircha Shovot)

## 3. Sahifa tuzilishi

Tuman sahifasi yuqoridan pastga: **4 card → xarita → jadval**.

Sahifa kengligi `max-w-7xl` (1280px) dan **`max-w-screen-2xl`** (1536px) ga oshadi. Sabab: keng monitorda ikki yonda katta bo'sh joy qolyapti, xarita esa kenglikdan foyda ko'radi. Bundan kattaroq qilinmaydi — jadval qatorlari o'qish uchun juda uzayib ketadi.

Tor ekranda: cardlar 2×2 ga tushadi, xarita to'liq kenglikda qoladi, jadval o'z `overflow-x-auto` konteynerida siljiydi.

## 4. Xarita

**Texnologiya:** Leaflet + OpenStreetMap plitkalari, ustiga GeoJSON poligonlar.

Yangi paketlar: `leaflet`, `@types/leaflet`. Bular loyihaga qo'shiladigan **birinchi** UI kutubxonasi — shu sababli faqat xarita komponentida ishlatiladi, boshqa joyga tarqalmaydi.

**Rang sxemasi** — shu haftadagi o'zgargan xonadonlar soni bo'yicha:

| Oraliq | Rang | Ma'no |
|---|---|---|
| 0 | `#e2e8f0` (slate-200) | o'zgarish yo'q |
| 1–5 | `#bbf7d0` (green-200) | boshlangan |
| 6–15 | `#4ade80` (green-400) | faol |
| 16+ | `#15803d` (green-700) | jadal |

Chegara chizig'i `#94a3b8`, qalinlik 1. Sichqoncha ustiga kelganda chegara qalinlashadi va tooltip chiqadi: mahalla nomi + shu haftadagi/bugungi o'zgarish. Bosilganda mahalla sahifasi ochiladi.

Xarita balandligi 420px, boshlang'ich ko'rinish tuman chegarasiga moslanadi (`fitBounds`).

**Internet uzilishi:** OSM plitkalari yuklanmasa fon kulrang qoladi, **poligonlar va ranglar baribir ko'rinadi** — ular bizning ma'lumotimiz. Xarita xaritagramga aylanadi, yo'qolmaydi. Bu qabul qilingan xatti-harakat, alohida ishlov talab qilmaydi.

### 4.1 GeoJSON endpointi

`GET /api/mahalla/executive/districts/{district}/geojson` — asosiy javobdan **alohida**.

Bu yerda `{district}` **majburiy** (asosiy endpointdagidan farqli). Sabab: Laravel ixtiyoriy marshrut parametrini faqat URL oxirida qo'llab-quvvatlaydi, o'rtada turgani ishlamaydi. Frontend uchun bu muammo emas — u chegaralarni jadval yuklanganidan keyin so'raydi va tuman `id` si allaqachon qo'lida bo'ladi. Sabab: ~47 KB, kamdan-kam o'zgaradi, alohida keshlanadi va jadval yuklanishini sekinlashtirmaydi.

Serverda soddalashtiriladi:

```sql
ST_AsGeoJSON(ST_SimplifyPreserveTopology(m.boundary, 0.0003))
```

`0.0003` daraja ≈ **33 m**. O'lchangan: xom 378 KB → 46.6 KB. Ekranda (35 km kenglikdagi tuman, 1500px) farqi ko'rinmaydi.

Javob — standart `FeatureCollection`, har `Feature` ning `properties` da: `id`, `name`. **O'zgarish sonlari GeoJSON'ga QO'SHILMAYDI** — ular asosiy javobda keladi va frontendda `id` bo'yicha bog'lanadi. Shunda geometriya keshi raqamlar yangilanganda ham amal qiladi.

## 5. KPI cardlar

| Card | Hisoblash |
|---|---|
| **Бугун ўзгарган** | `COUNT(DISTINCT house_id)`, `is_change=true`, bugundan |
| **Шу ҳафтада ўзгарган** | xuddi shu, hafta boshidan |
| **Фаол маҳаллалар** | shu haftada ≥1 o'zgarish bo'lgan mahallalar soni / jami mahalla |
| **Текшириш кутилмоқда** | `decision='flagged'` VA `reviewed_by IS NULL`, tuman bo'yicha |

**Cardlar jadval bilan bir xil raqam BERMAYDI va bermasligi kerak.** Jadvaldagi ЖАМИ har zona uchun alohida `COUNT(DISTINCT house_id)`; card esa zonadan qat'i nazar `COUNT(DISTINCT house_id)`. Ikki zonasi o'zgargan xonadon jadvalda ikkita ustunda ko'rinadi, cardda esa bir marta sanaladi.

Ya'ni card «nechta **xonadonda** ish bo'ldi», jadval «nechta xonadonning **shu qismida** ish bo'ldi» degan savolga javob beradi. Shu sababli card raqamini zona ustunlarini qo'shib olib bo'lmaydi — alohida so'rov kerak.

**Учинчи ва тўртинчи** mavjud so'rovlardan kelib chiqadi yoki bitta qo'shimcha so'rov talab qiladi:

```sql
-- Tekshirish kutilmoqda (mahalla ulanishi)
SELECT count(*) FROM zone_observations o
JOIN houses h ON h.id = o.house_id
WHERE h.district_id = ? AND o.decision = 'flagged' AND o.reviewed_by IS NULL
```

«Фаол маҳаллалар» alohida so'rovsiz hisoblanadi — `district()` allaqachon har mahalla uchun haftalik sonlarni biladi.

Cardlar `district` javobiga `summary` obyekti sifatida qo'shiladi.

## 6. Mahalla sahifasi statistikasi

Mavjud 4 qatorli jadval **saqlanadi**, ustiga uch blok qo'shiladi.

### 6.1 30 kunlik dinamika

Kunlar bo'yicha o'zgargan xonadonlar soni, ustunli diagramma (CSS/SVG, kutubxonasiz — `AdminReports.vue` da shunday naqsh bor).

```sql
SELECT to_char(o.observed_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Tashkent', 'YYYY-MM-DD') AS kun,
       count(DISTINCT o.house_id) AS soni
FROM zone_observations o JOIN houses h ON h.id = o.house_id
WHERE h.mahalla_id = ? AND o.is_change = true AND o.observed_at >= ?
GROUP BY 1 ORDER BY 1
```

**Vaqt mintaqasi nozikligi:** `observed_at` — `timestamp without time zone`, ichida UTC saqlanadi. Kunlarga ajratishda mahalliy vaqtga o'girilishi SHART, aks holda kechqurun 19:00 dan keyingi o'zgarishlar (Toshkent bo'yicha ertaga) noto'g'ri kunga tushadi. Shuning uchun ikki bosqichli `AT TIME ZONE`.

Kuzatuv bo'lmagan kunlar ham o'qda ko'rinadi (nol balandlik) — aks holda "har kuni ish bo'lgan" degan yolg'on taassurot qoladi. Ya'ni servis 30 ta kunning hammasini qaytaradi, bazadan kelmagan kunlar `count = 0` bilan to'ldiriladi.

Oraliq boshi ham **mahalliy vaqt bo'yicha** olinadi: Toshkent bo'yicha bugungi kunning boshidan 29 kun orqaga, so'ng UTC ga o'giriladi. Aks holda oraliq chegarasi kun o'rtasiga tushib, birinchi kun chala chiqadi.

### 6.2 Zona holati taqsimoti

Har zona uchun gorizontal to'plangan (stacked) chiziq: nechta xonadon qaysi holatda.

```sql
SELECT s.zone, s.status, count(*) AS soni
FROM house_zone_states s JOIN houses h ON h.id = s.house_id
WHERE h.mahalla_id = ?
GROUP BY s.zone, s.status
```

**Muhim: to'rtinchi segment «кузатилмаган».** `house_zone_states` da qator FAQAT xonadon birinchi marta kuzatilganda paydo bo'ladi (`ObservationAnalyzer` uni `firstOrCreate` bilan yaratadi). Ya'ni «кузатилган» ning amaliy ta'rifi — shu jadvalda qatori borligi. Agar 837 xonadonli mahallada 5 tasi kuzatilgan va hammasi «тугалланган» bo'lsa, taqsimot **100% yashil** ko'rsatadi — go'yo butun mahalla tugallangan.

Shuning uchun har zona uchun:

```
кузатилмаган = jami_xonadon − (o'sha zona bo'yicha holat qatorlari yig'indisi)
```

va u diagrammada kulrang segment sifatida ko'rsatiladi. Shunda 5/837 holati 99% kulrang bo'lib ko'rinadi — haqiqat qanday bo'lsa shunday.

Ranglar: `needs_work` qizil, `in_progress` sariq, `completed` yashil, `good` ko'k, `кузатилмаган` `#e2e8f0`.

### 6.3 So'nggi o'zgarishlar

Oxirgi 10 ta tasdiqlangan o'zgarish: sana, zona, manzil va AI yozgan tavsif.

```sql
SELECT o.id, o.zone, o.observed_at, o.ai_result, h.address
FROM zone_observations o JOIN houses h ON h.id = o.house_id
WHERE h.mahalla_id = ? AND o.is_change = true
ORDER BY o.observed_at DESC LIMIT 10
```

Tavsif `ai_result` JSON ichidagi `change_description` dan olinadi. Bo'sh bo'lsa qator baribir ko'rsatiladi (sana/zona/manzil bilan), tavsif o'rniga «тавсиф йўқ» turadi — o'zgarish faktini yashirmaslik kerak.

**Manzil ko'rsatiladi, rasm YO'Q.** `viloyat` rolida `photos.view` ruxsati yo'q (birinchi bosqich qarori) va uni bu ish doirasida o'zgartirmaymiz.

## 7. API o'zgarishlari

Yangi endpoint bittа:

| Metod | URL | Nom |
|---|---|---|
| GET | `/api/mahalla/executive/districts/{district?}/geojson` | `api.mahalla.executive.district.geojson` |

Mavjud ikkita endpoint javobi kengaytiriladi:

**`districts/{district?}`** — `summary` qo'shiladi:

```json
"summary": {
  "changed_today": 20,
  "changed_week": 143,
  "active_mahallas": 7,
  "total_mahallas": 52,
  "pending_reviews": 12
}
```

**`mahallas/{mahalla}`** — uch maydon qo'shiladi:

```json
"dynamics": [ { "date": "2026-07-20", "count": 3 } ],
"zone_status": [
  { "zone": "yard", "label": "Томорқа", "households": 837,
    "statuses": [ { "status": "completed", "label": "Тугалланган", "count": 4 } ],
    "unobserved": 833 }
],
"recent_changes": [
  { "id": "uuid", "zone": "yard", "zone_label": "Томорқа",
    "address": "Наврўз кўч. 14", "observed_at": "2026-07-20T09:12:00Z",
    "description": "ўт босган ер тозаланиб экишга тайёрланган" }
]
```

Barchasi `mahalla.viewer` gvardiyasida qoladi.

## 8. Fayllar

**Yangi (backend):**
- `app/Domains/Mahalla/Http/Controllers/Api/Executive/DistrictGeoJsonController.php`
- `app/Domains/Mahalla/Services/ExecutiveMahallaStats.php` — dinamika + zona holati + so'nggi o'zgarishlar

**O'zgaradi (backend):**
- `app/Domains/Mahalla/Services/ExecutiveStats.php` — `summary()` metodi
- `Api/Executive/DistrictDashboardController.php`, `MahallaDashboardController.php`
- `routes/api/mahalla.php`
- `tests/Feature/Mahalla/ExecutiveDashboardTest.php`

**Yangi (frontend):**
- `src/components/executive/ChangeMap.vue` — Leaflet o'ramasi (yagona joy)
- `src/components/executive/KpiCard.vue`
- `src/components/executive/DynamicsChart.vue`
- `src/components/executive/ZoneStatusBars.vue`

**O'zgaradi (frontend):**
- `src/pages/executive/ExecutiveDistrict.vue`, `ExecutiveMahalla.vue`
- `src/stores/executive.ts` (geojson holati), `src/types/index.ts`
- `package.json` (`leaflet`, `@types/leaflet`)

`ExecutiveMahallaStats` alohida servis sifatida ajratiladi: `ExecutiveStats` allaqachon tuman kesimi bilan band, mahalla statistikasi uch mustaqil so'rovdan iborat va o'z faylida turgani tushunarliroq.

## 9. Testlar

`tests/Feature/Mahalla/ExecutiveDashboardTest.php` ga qo'shiladi:

1. GeoJSON endpointi `viloyat` uchun 200 va to'g'ri `FeatureCollection` qaytaradi; `properties` da `id` va `name` bor
2. GeoJSON `deputat` uchun 403
3. Har `Feature` ning `id` si `rows[].mahalla.id` bilan mos (frontend bog'lanishi ishlashi uchun)
4. `summary.changed_week` xonadonni zonalardan qat'i nazar **bir marta** sanaydi (bitta uy ikki zonada o'zgarsa natija 1, 2 emas)
5. `summary.active_mahallas` — o'zgarish qo'shilganda ortadi (delta)
6. `summary.pending_reviews` faqat `flagged` va `reviewed_by IS NULL` ni sanaydi
7. **Zona holatida `unobserved` to'g'ri hisoblanadi** — bitta xonadon kuzatilgan mahallada `unobserved = households − 1`
8. Dinamika mahalliy kun chegarasi bo'yicha guruhlanadi (Toshkent bo'yicha bugungi 00:30 kuzatuvi bugungi kunga tushadi)
9. `recent_changes` faqat `is_change=true` ni qaytaradi va `observed_at` bo'yicha kamayish tartibida

Testlar mavjud infratuzilmada: `DatabaseTransactions`, haqiqiy kadastr geo'si, dev bazasi o'zgarmaydi.

## 10. Ochiq masalalar

Yo'q. Qarorlar:
- Xarita: Leaflet + OSM (foydalanuvchi tanlovi)
- Cardlar: hajm (3) + harakat talab qiluvchi (1)
- Mahalla statistikasi: dinamika, zona holati, so'nggi o'zgarishlar (deputat faolligi CHIQARILDI)
- Diagrammalar qo'lda SVG/CSS — chart kutubxonasi qo'shilmaydi
