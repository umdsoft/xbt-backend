# «Davlat dasturlari va qurilish-ta'mirlash ijrosi» platformasi — dizayn

**Sana:** 2026-08-13
**Domen:** `qurilish`
**Holat:** tasdiqlangan (brainstorming → spec)
**Manba:** `D:\kadr\2026_БАРЧА_ДАСТУР_12.08.26.xlsx`

---

## 1. Maqsad

Xorazm viloyati bo'yicha davlat dasturlari asosida amalga oshirilayotgan qurilish,
rekonstruksiya va ta'mirlash ishlarini **boshidan oxirigacha** nazorat qiluvchi
platforma. `digital-xorazm` ekotizimining navbatdagi moduli: markaziy PostgreSQL
(`kbt` DB, `qurilish` schema) va markaziy Sanctum SPA autentifikatsiyasidan foydalanadi.

Ikki asosiy vazifa:

1. **Ijro nazorati** — har obyekt qaysi dastur asosida, qancha mablag'ga, kim
   buyurtmachi/pudratchi ekani va **har bir bosqichi** qay holatda ekani kuzatiladi;
   hujjatlari yagona serverda versiyalab saqlanadi.
2. **Ta'mirtalab reyestr** — har tashkilot kelgusi yil/dasturlar uchun ta'mirtalab
   obyektlar ro'yxatini yuritadi; bu ro'yxat vaqti kelganda real loyihaga aylanadi.

---

## 2. Manba tahlili — xulosalar

### 2.1 Inventar

31 varaq: **8 ta obyekt darajasida** (import qilinadi), **22 ta СВОД pivot**
(import QILINMAYDI — tizim jonli hisoblaydi), **1 ta `ПАСПОРТ`** (ta'mirtalab agregat).

| Dastur | Varaq | Obyekt | Limit (mln so'm) |
|---|---|---:|---:|
| ПҚ-393 Qaror (Investitsiya) | `ПҚ-393` | 218 | 3 446 974,7 |
| «Drayver loyihalar» 1-bosqich | `ДРАЙВЕР` | 44 | 70 444,2 |
| «Tashabbusli byudjet» 1-mavsum | `ОПЕН` | 108 | 160 246,8 |
| «Og'ir» tumanlar (ПҚ-298) | `ОҒИР ТУМАН` | 39 | 180 000,0 |
| «Og'ir» mahallalar (ПҚ-298) | `ОҒИР МФЙ` | 115 | 345 000,0 |
| «Yangi O'zbekiston qiyofasidagi tuman» | `ЯНГИ.ЎЗБ.ТУМАН` | 32 | 130 000,0 |
| «Yangi O'zbekiston qiyofasidagi mahalla» | `ЯНГИ.ЎЗБ.МФЙ` | 22 | 110 000,0 |
| Tadbirkor mablag'i hisobidan DXSh (PPP) | `33 ТА ДХШ` | 33 | 0 |
| **JAMI** | | **611** | **4 442 665,7** |

Ekstraksiya faylning o'z `СВОД ДАСТУР` jamlanmasi bilan **aynan mos**
(611 / 4 442 665,66) — qator klassifikatsiyasi to'g'ri ekanining isboti.

**Qator turlari:** `A` ustunida raqam bo'lsa → obyekt qatori; `F` to'lgan-u `A` bo'sh
bo'lsa → guruh sarlavhasi (`ПҚ-393`da soha va ish turi, boshqalarda tuman).

### 2.2 Object ID ichida SOATO kodi (asosiy kashfiyot)

```
2601334060102001
  ^^ ^^^^^
  ││ └── [4:9] → '17' + '33406' = 1733406 = master.districts.soato_code
  └───── ro'yxatga olish yili (26=2026, 25, 24, 23, 22, 19)
```

| Natija | Soni |
|---|---:|
| SOATO derivatsiyasi muvaffaqiyatli | 566 |
| Maxsus/nomos kodlar (`992000` tumanlararo ×3, `101222`, `100602`, `304050`) | 6 |
| ID umuman yo'q (33 DXSh + 6 ta `ПҚ-393`) | 39 |

Boshqa 6 varaqda ID'dan olingan tuman guruh-sarlavha bilan **360/360 = 100% mos**.
`ПҚ-393`ning `C` ustunida **12 ta xato** bor.

Xulosa: **fuzzy district matcher kerak emas.** Object ID — hokim manba;
`C` ustuni / guruh-qator — zaxira va tekshiruv.

### 2.3 Ustun toifalari (8 varaq bir xil sxemada A…AT)

| Toifa | Ustunlar |
|---|---|
| Identifikatsiya | `A` №, `B` Объект ID, `C` hudud (ПҚ-393) / soha (qolgani), `F` nom, `G` muddat |
| Tashkilotlar | `D` loyihachi, `E` buyurtmachi, `AS` pudratchi |
| Moliya | `H` obyekt soni, `I` limit, `J` yildan-yilga o'tuvchi, `K` yangi |
| 1. Loyihachini aniqlash | `L` e'lon berilgan, `M` berilmagan, `N` aniqlangan, `O` aniqlanmagan |
| 2. LSD | `P` ishlab chiqilgan, `Q` chiqilmagan, `R` jarayonda |
| 3. Shaharsozlik eksp. | `S` kiritilgan, `T` xulosa, `U` ko'rilmoqda, `V` kiritilmagan |
| 4. Kompleks eksp. (shartli) | `W` talab, `X` kiritilgan, `Y` xulosa, `Z` ko'rilmoqda, `AA` e'tiroz, `AB` kiritilmagan |
| 5. Tender | `AC` talab, `AD` e'lon, `AE` aniqlangan, `AF` jarayon, `AG` tender qiymati, `AH` e'lonsiz |
| 6. Shartnoma + ijro | `AI` shartnoma soni, `AJ` qiymati, `AK` o'zlashtirilgan, `AL` %, `AM` moliyalashtirilgan, `AN` % |
| 7. Topshirish | `AO` reja, `AP` amalda, `AQ` qoldiq, `AR` muddat buzilgan |
| Qo'shimcha | `AT` izoh; `ПҚ-393`+`ДРАЙВЕР`: `AV`–`BG` oylik grafik, `BH`/`BI` jami |

Bosqich bayroqlari toza `1` / bo'sh — matn axlati yo'q.

### 2.4 Ma'lumot sifati muammolari

**Kritik**

| Muammo | Miqdor | Yechim |
|---|---:|---|
| Buyurtmachi imlo xatosi: `"Ягона буюрмачи хизмати"` (205) vs `"Ягона буюртмачи хизмати"` (124) | 2 variant = 1 tashkilot | `organization_aliases` |
| Loyihachi dublikatlari (tirnoq, `MCHJ` va `MAS'ULIYATI CHEKLANGAN JAMIYAT`, kirill/lotin, `\n`) | 106 xom → ~60 real | `organization_aliases` |
| Pudratchi dublikatlari | 240 xom → ~200 real | `organization_aliases` |
| 33 DXSh obyektlarida Объект ID yo'q | 39 | tuman guruh-qatordan |
| `ПҚ-393` `C` ustunidagi hudud xatolari | 12 | ID hokim |

Kanonik buyurtmachi ro'yxati `СВОД ЗАКАЗЧИК` varag'ida tayyor — **21 tashkilot**.

**O'rtacha**

| Muammo | Miqdor |
|---|---:|
| Muddat 4 formatda: `2026 йил` (92), `2026 й` (48), `DD.MM.YY й` (~380), `2025-2026 йй` (10), `2026-2027 йй` (4) | 611 |
| Soha normallashtirilmagan (`Ички йўл`/`ички йўл`/`Ички йўллар`, `МТТ`/`мактаб`) | 18 xom → ~9 |
| `Бошқа` sohasi aslida: 92 ichki yo'l, 29 ichimlik suv, 15 ko'cha, 12 haqiqiy aralash | 150 |
| Loyihachi/pudratchi bo'sh | 95 (16%) |
| Oylik grafik bo'sh (ПҚ-393+ДРАЙВЕР ichida) | 153/262 |
| `Тупроққалъа`/`Тупроққальа` | 2 variant |
| ID anomaliyalari (1 takror, 1 nuqtali, 2 ta 14-xonali) | 4 |

**Moliyaviy ziddiyatlar yo'q:** `o'zlashtirilgan > shartnoma` = 0; `% > 100` = 0;
`shartnoma bor, pudratchi yo'q` = 0. Faqat 5 ta obyektda pudratchi bor, shartnoma soni yo'q.

### 2.5 `ПАСПОРТ` — ta'mirtalab

8 soha kesimida **faqat agregat**: jami 1 298 obyekt, ta'mirtalab **368**,
2026-yilga 129 (813 482 mln), 2027-yilga 23 (262 659 mln),
**moliyalashtirish manbai aniq emas 216** (3 450 748 mln).

Obyekt darajasidagi ta'mirtalab ro'yxati mavjud emas → platforma uni noldan yig'adi,
`ПАСПОРТ` esa `repair_needs` jadvalidan **jonli hisoblangan hisobot**ga aylanadi.

---

## 3. Arxitektura

```
DB          kbt (PostgreSQL 16) → yangi schema: qurilish
connection  'qurilish' → search_path = qurilish,master,public   (config/database.php)
backend     app/Domains/Qurilish/{Models,Services,Http,Support,Console,Database/Seeders}
routes      routes/api/qurilish.php  →  routes/api.php'ga require
auth        auth.systems += ['code' => 'qurilish', 'name' => 'Қурилиш дастурлари ижроси']
frontend    D:\kadr\qurilish (Vue 3.5 + TS + Pinia + Tailwind v4 + Vite)
subdomen    qurilish.digital-xorazm.uz
```

Migratsiya naqshi — `2026_08_06_120000_create_murojaat_schema.php` aynan:
`CREATE SCHEMA IF NOT EXISTS`, idempotent, forward-only, mavjud jadval → skip,
**cross-schema FK yo'q** (uuid + index).

---

## 4. Ma'lumot modeli (`qurilish` schema)

### 4.1 Spravochniklar

**`programs`** — davlat dasturlari

```
id uuid pk, code varchar(40) unique, name_cyr, name_lat, legal_basis varchar(60),
year smallint, sort_order int, is_active bool, timestamps
```

Boshlang'ich 8 qator: `pq393`, `drayver`, `open`, `ogir_tuman`, `ogir_mfy`,
`yangi_uzb_tuman`, `yangi_uzb_mfy`, `dxsh`.

**`sectors`** — sohalar (20 kanonik)

```
id uuid pk, code varchar(40) unique, name_cyr, name_lat,
default_department_org_id uuid null, sort_order int, is_active bool, timestamps
```

**`organizations`** — yagona tashkilot reyestri

```
id uuid pk, name_cyr, name_lat, short_name null, inn varchar(20) null,
is_customer bool, is_designer bool, is_contractor bool, is_department bool,
district_id uuid null, is_active bool, timestamps
```

Bitta tashkilot bir vaqtda bir necha rolda bo'lishi mumkin (masalan
«Ҳудудий электр тармоқлари» АЖ ham buyurtmachi, ham loyihachi, ham pudratchi).

**`organization_aliases`** — import normalizatsiyasi

```
id uuid pk, organization_id uuid → organizations, alias_raw text unique,
alias_norm text index, created_at
```

`alias_norm` = tirnoq/bo'shliq/`\n` tozalangan, kirill→lotin transliteratsiya
qilingan, upper-case ko'rinish. Import yangi alias uchraganda `alias_norm` bo'yicha
qidiradi; topmasa yangi `organizations` qatori yaratadi va aliasni bog'laydi.

### 4.2 Yadro

**`objects`**

```
id uuid pk
external_id varchar(20) null unique          -- Объект ID рақами
program_id uuid null → programs              -- qoralama holatida null
sector_id uuid null → sectors
district_id uuid null                        -- master.districts (FK yo'q, index)
mahalla_id uuid null                         -- master.mahallas (FK yo'q, index)
name text
work_type varchar(30) null                   -- yangi_qurish|rekonstruksiya|mukammal_tamirlash|kapital_tamirlash|joriy_tamirlash
customer_org_id   uuid null → organizations
designer_org_id   uuid null → organizations
contractor_org_id uuid null → organizations
department_org_id uuid null → organizations  -- boshqarma (sectors.default'dan)
limit_amount      numeric(18,3) default 0    -- mln so'm
tender_amount     numeric(18,3) default 0
contract_amount   numeric(18,3) default 0
disbursed_amount  numeric(18,3) default 0    -- o'zlashtirilgan
financed_amount   numeric(18,3) default 0    -- moliyalashtirilgan
deadline_raw varchar(60) null                -- xom matn (audit uchun)
deadline_date date null
deadline_year smallint null
is_carryover bool default false              -- yildan yilga o'tuvchi
lifecycle varchar(20) default 'reja'         -- qoralama|reja|jarayonda|tugallangan|toxtatilgan
current_stage varchar(30) null
handover_planned bool default false
handover_done bool default false
note text null                               -- ИЗОХ
source varchar(10) default 'manual'          -- import|manual
created_by uuid null, updated_by uuid null
timestamps, softDeletes
```

Indekslar: `(program_id)`, `(district_id)`, `(sector_id)`, `(customer_org_id)`,
`(department_org_id)`, `(lifecycle)`, `(current_stage)`, `(deadline_date)`.

`is_overdue` **saqlanmaydi** — `deadline_date < CURRENT_DATE AND NOT handover_done`
sifatida jonli hisoblanadi (xom `AR` ustuni faqat import tekshiruvi uchun ishlatiladi).

**`object_stages`** — 8 qator/obyekt

```
id uuid pk, object_id uuid → objects (cascade), stage_code varchar(30),
status varchar(30),          -- talab_etilmaydi|boshlanmagan|jarayonda|yakunlangan|etiroz_bilan_qaytarilgan
started_at date null, completed_at date null,
responsible_user_id uuid null, note text null, timestamps
UNIQUE(object_id, stage_code)
```

**`object_monthly_plan`**

```
id uuid pk, object_id uuid → objects (cascade), year smallint, month smallint,
planned_amount numeric(18,3) default 0, actual_amount numeric(18,3) default 0, timestamps
UNIQUE(object_id, year, month)
```

**`object_documents`**

```
id uuid pk, object_id uuid → objects (cascade), stage_code varchar(30) null,
category varchar(40),        -- lsd|ekspertiza|shartnoma|dalolatnoma|surat|boshqa
original_name varchar(500), stored_path varchar(500), mime varchar(150),
size bigint, sha256 char(64) index, version int default 1,
uploaded_by uuid, uploaded_at timestamp, softDeletes
```

Saqlash: `storage/app/private/qurilish/{object_id}/{uuid}.{ext}`.
Yuklab olish faqat `QurilishAccess` tekshiruvidan keyin, nginx `X-Accel-Redirect`
orqali (advisor `Concerns\StreamsFiles` naqshi). Fayl turi oq ro'yxati:
`pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx`. Maksimum 25 MB/fayl.
Bir xil `sha256` — yangi versiya sifatida qayd etiladi, disk nusxasi qayta ishlatiladi.

**`object_audit_log`**

```
id uuid pk, object_id uuid index, user_id uuid null, action varchar(40),
field varchar(60) null, old_value text null, new_value text null,
ip varchar(45) null, created_at timestamp index
```

### 4.3 Ta'mirtalab reyestr

**`repair_needs`**

```
id uuid pk, department_org_id uuid → organizations, sector_id uuid → sectors,
district_id uuid null, mahalla_id uuid null, name text,
condition_desc text null, estimated_amount numeric(18,3) null,
target_year smallint null,             -- 2026|2027|null (manba noaniq)
funding_source_known bool default false,
priority smallint default 3,           -- 1 yuqori … 5 past
status varchar(30) default 'yigilgan', -- yigilgan|korib_chiqilmoqda|dasturga_kiritildi|rad_etildi
promoted_object_id uuid null → objects,
created_by uuid, timestamps, softDeletes
```

**`repair_need_files`** — `object_documents` bilan bir xil sxema, `repair_need_id` bilan.

### 4.4 Kirish nazorati

**`profiles`**

```
id uuid pk, user_id uuid unique,       -- = auth.users.id (FK yo'q)
role varchar(20),                      -- hokimlik|prokuratura|buyurtmachi|boshqarma|admin
organization_id uuid null → organizations,
district_id uuid null, position varchar(200) null, is_active bool, timestamps
```

**`import_sessions`**

```
id uuid pk, file_name varchar(500), records_count int, imported_by uuid null,
is_active bool, notes text null, timestamps
```

---

## 5. Bosqich workflow (holat mashinasi)

| # | `stage_code` | Nomi | Manba ustunlari |
|---|---|---|---|
| 1 | `designer_selection` | Loyihachini aniqlash | `L, M, N, O` |
| 2 | `design_estimate` | Loyiha-smeta hujjatlari (LSD) | `P, Q, R` |
| 3 | `urban_planning` | Shaharsozlik hujjatlari ekspertizasi | `S, T, U, V` |
| 4 | `complex_expertise` | Kompleks ekspertiza **(shartli)** | `W, X, Y, Z, AA, AB` |
| 5 | `tender` | Tender savdolari | `AC…AH` |
| 6 | `contract` | Shartnoma | `AI, AJ` |
| 7 | `execution` | 2026 ijro + oylik grafik | `AK, AL, AM, AN` |
| 8 | `handover` | Topshirish | `AO, AP, AQ, AR` |

**Qoidalar (server tomonda majburlanadi):**

- Bosqichlar ketma-ket. Oldingi bosqich `yakunlangan` bo'lmasa, keyingisini
  `jarayonda`ga o'tkazib bo'lmaydi.
- 4-bosqich: manbada `W` (talab etiladi) bo'sh bo'lsa → `talab_etilmaydi`;
  bu holda 3 → 5 o'tish ruxsat etiladi.
- `etiroz_bilan_qaytarilgan` (`AA`) faqat 4-bosqichda bo'ladi va uni qayta
  `jarayonda`ga qaytarish mumkin.
- `objects.current_stage` = eng katta tartibli `jarayonda` yoki oxirgi
  `yakunlangan` bosqich; servis qatlamida qayta hisoblanadi.
- Har o'tish `object_audit_log`ga `action='stage_change'` bilan yoziladi.
- `lifecycle='qoralama'` obyektlarda bosqich o'zgartirish taqiqlanadi.

**Import mapping (bayroq → status):**

```
1: N=1 → yakunlangan;  L=1 va N bo'sh → jarayonda;  M=1 → boshlanmagan
2: P=1 → yakunlangan;  R=1 → jarayonda;  Q=1 → boshlanmagan
3: T=1 → yakunlangan;  U=1 → jarayonda;  S=1 va T bo'sh → jarayonda;  V=1 → boshlanmagan
4: W bo'sh → talab_etilmaydi;  Y=1 → yakunlangan;  AA=1 → etiroz_bilan_qaytarilgan;
   Z=1 yoki X=1 → jarayonda;  AB=1 → boshlanmagan
5: AE=1 → yakunlangan;  AF=1 yoki AD=1 → jarayonda;  AH=1 → boshlanmagan
6: AI>=1 → yakunlangan;  aks holda boshlanmagan
7: AL>=100 → yakunlangan;  AK>0 → jarayonda;  aks holda boshlanmagan
8: AP=1 → yakunlangan;  AO=1 → boshlanmagan
```

---

## 6. Rollar (RBAC)

| Rol | Ko'rish | Yozish | Scope |
|---|---|---|---|
| `hokimlik` | hammasi | — | viloyat |
| `prokuratura` | hammasi | — | viloyat |
| `buyurtmachi` | o'z obyektlari | bosqich, moliya, hujjat, izoh | `organization_id` = `customer_org_id` |
| `boshqarma` | o'z obyektlari + ta'mirtalab | obyekt kiritish (jumladan qoralama), ta'mirtalab reyestr | `organization_id` = `department_org_id` |
| `admin` | hammasi | hisoblar, spravochniklar, import | viloyat |

**Amalga oshirish** (advisor naqshi aynan):

```
Route::middleware(['auth:sanctum', 'qurilish'])->prefix('qurilish')…
App\Domains\Qurilish\Http\Middleware\EnsureQurilish   → 403 «Бу тизимга рухсат йўқ.»
App\Domains\Qurilish\Support\QurilishAccess::can($user, $ability, $object = null)
App\Domains\Qurilish\Support\QurilishScope::apply($query, $user)
```

Hisoblar **tizim tomonidan yaratiladi**: admin `POST /api/qurilish/users` orqali
`auth.users` + `auth.user_system_access(system='qurilish', role)` + `qurilish.profiles`
yozuvlarini bitta tranzaksiyada yaratadi (advisor `AdvisorAdminService` naqshi).

---

## 7. ETL

```
xlsx ──python/openpyxl (read_only, iter_rows)──▶ 4 ta CSV
        ├─ objects.csv          611 qator
        ├─ stages.csv           ~4 888 qator (611 × 8)
        ├─ monthly.csv          ~1 308 qator (109 obyekt × 12)
        └─ org_aliases.csv      ~370 xom nom
                    │
                    ▼
        php artisan qurilish:import --dir=storage/app/import/qurilish
```

**Normalizatsiya qoidalari:**

1. **Tuman** — `external_id[4:9]` → `'17' + kod` → `master.districts.soato_code`.
   Topilmasa (`992000` va boshqalar) yoki ID yo'q bo'lsa → guruh-qatordagi tuman nomi
   bo'yicha `name_cyr` moslash. Ikkalasi ham bo'lmasa → `district_id = null` +
   import hisobotida ogohlantirish.
2. **Tashkilot** — `alias_norm` (tirnoqsiz, `\n`siz, transliteratsiya, upper)
   bo'yicha `organization_aliases`da qidirish; topilmasa yangi `organizations`
   yaratish. Kanonik urug' — `СВОД ЗАКАЗЧИК`dagi 21 buyurtmachi.
3. **Soha** — `ПҚ-393`da guruh-qatordan; boshqalarda `C` ustunidan. `Бошқа`/bo'sh
   bo'lsa → obyekt nomi bo'yicha kalit so'zli tasniflagich
   (`йўл` → ichki yo'l, `сув` → ichimlik suv, `кўча` → ko'cha, `мактаб` → maktab, …);
   u ham aniqlamasa → `boshqa`.
4. **Ish turi** — `ПҚ-393`da guruh-qator konteksti; boshqalarda obyekt nomidagi
   `қуриш`/`реконструкция`/`таъмирлаш` kalit so'zlari.
5. **Muddat** — 4 format parseri: `DD.MM.YY й` → aniq sana; `YYYY йил`/`YYYY й` →
   `deadline_year` + `deadline_date = YYYY-12-31`; `YYYY-YYYY йй` → oxirgi yil.
   Xom qiymat `deadline_raw`da saqlanadi.
6. **Boshqarma** — `sectors.default_department_org_id`dan.
7. **Idempotentlik** — `external_id` bo'yicha upsert. `external_id` yo'q obyektlar
   (`33 ТА ДХШ` + 6 ta) uchun sun'iy kalit: `{program_code}:{sheet_row}`.

**Xavfsizlik:** PII yo'q (faqat tashkilot nomlari va mansabdor bo'lmagan
operatsion ma'lumot). Xom `.xlsx`/`.csv` → `.gitignore`.

---

## 8. API yuzasi (`routes/api/qurilish.php`)

```
GET    /api/qurilish/context               -- rol, scope, ruxsatlar, spravochniklar
GET    /api/qurilish/dashboard             -- yuqori qator + kesimlar
GET    /api/qurilish/dashboard/svod/{dim}  -- dim: dastur|soha|tuman|buyurtmachi
GET    /api/qurilish/dashboard/funnel      -- 8 bosqich voronkasi
GET    /api/qurilish/dashboard/map         -- tuman xoroplet

GET    /api/qurilish/objects               -- filtr+sahifalash (scope avtomatik)
POST   /api/qurilish/objects               -- boshqarma|admin
GET    /api/qurilish/objects/{id}
PATCH  /api/qurilish/objects/{id}
GET    /api/qurilish/objects/{id}/stages
PATCH  /api/qurilish/objects/{id}/stages/{stage}
GET    /api/qurilish/objects/{id}/monthly
PUT    /api/qurilish/objects/{id}/monthly
GET    /api/qurilish/objects/{id}/documents
POST   /api/qurilish/objects/{id}/documents
GET    /api/qurilish/documents/{id}/download
DELETE /api/qurilish/documents/{id}
GET    /api/qurilish/objects/{id}/audit

GET    /api/qurilish/repair-needs          -- ta'mirtalab reyestr
POST   /api/qurilish/repair-needs
PATCH  /api/qurilish/repair-needs/{id}
POST   /api/qurilish/repair-needs/{id}/promote   -- → objects (qoralama)
GET    /api/qurilish/repair-needs/pasport        -- ПАСПОРТ jonli hisoboti

GET    /api/qurilish/export/svod            -- Excel
GET    /api/qurilish/export/objects         -- Excel
GET    /api/qurilish/objects/{id}/report    -- yakuniy hisobot (PDF)

GET    /api/qurilish/users                  -- admin
POST   /api/qurilish/users
PATCH  /api/qurilish/users/{user}
POST   /api/qurilish/users/{user}/reset-password
GET    /api/qurilish/organizations          -- admin: spravochnik CRUD
GET    /api/qurilish/sectors
```

---

## 9. Dashboard

**Yuqori qator:** jami loyiha · jami qiymat (trln) · o'zlashtirilgan % ·
muddat buzilgan obyekt soni · topshirilgan/reja.

**Kesimlar (drill-down):** `СВОД ДАСТУР` · `СВОД СОҲА` · `СВОД ТУМАН` ·
`СВОД ЗАКАЗЧИК` — barchasi jonli SQL agregatsiya, import qilinmaydi.

**Panellar:**

- Loyiha tayyorlik voronkasi — 8 bosqich bo'ylab obyekt oqimi
- Tender holati + tender iqtisodi (`limit_amount − tender_amount`)
- Shartnoma bajarilishi — `disbursed_amount / contract_amount` % taqsimoti
- Topshirish rejasi — reja/amalda/qoldiq, oylar bo'yicha
- Oylik ijro grafigi — reja vs amalda (jami va obyekt darajasida)
- Xarita — `master.districts.boundary` (PostGIS) xoroplet
- Ta'mirtalab paneli — `ПАСПОРТ` jonli varianti

**Til:** barcha ma'lumot **lotin** alifbosida (`name_lat` + zarur bo'lganda
`App\Domains\Qurilish\Support\Translit` — sport domenidagi naqsh).

---

## 10. Frontend (`D:\kadr\qurilish`)

Sport/mahalla naqshi: Vue 3.5 + TS + Pinia + Tailwind v4 + Vite + vue-router,
`axios` `withCredentials` (Sanctum SPA), Vitest.

```
src/
  layouts/      AppLayout
  pages/
    dashboard/  Dashboard.vue, Svod.vue, Funnel.vue, MapView.vue
    objects/    ObjectList.vue, ObjectDetail.vue, ObjectForm.vue, Stages.vue,
                Documents.vue, Monthly.vue, AuditLog.vue
    repair/     RepairList.vue, RepairForm.vue, Pasport.vue
    admin/      Users.vue, Organizations.vue, Sectors.vue, Import.vue
  stores/       auth, context, objects, dashboard, repair, admin
  lib/          api.ts, format.ts, translit.ts
  types/        qurilish.d.ts
```

---

## 11. Testlar

**Backend (Feature):**

- Auth: auth-siz → 401; `qurilish` tizimida bo'lmagan foydalanuvchi → 403
- RBAC: `hokimlik`/`prokuratura` yozish urinishi → 403; `buyurtmachi` boshqa
  tashkilot obyektini ko'rishga urinishi → 404; `boshqarma` scope
- Workflow: bosqichni sakrab o'tish → 422; shartli 4-bosqich; `qoralama`da taqiq
- Hujjat: ruxsatsiz yuklab olish → 403; noto'g'ri MIME → 422; hajm limiti; versiya
- Import: idempotentlik (ikki marta ishga tushirish → bir xil natija);
  SOATO derivatsiyasi; alias normalizatsiyasi; muddat parseri
- Dashboard: agregatsiya `СВОД ДАСТУР` bilan mos (611 / 4 442 665,66)
- Ta'mirtalab: `promote` → `objects` qoralama yaratadi va bog'lanish saqlanadi

**Frontend:** `vue-tsc` typecheck yashil, `vite build` yashil, store/router birlik testlari.

---

## 12. Qamrovdan tashqari (YAGNI)

- Mobil ilova — keyingi bosqich
- Fuzzy district matcher — kerak emas (SOATO derivatsiyasi yetarli)
- Redis kesh — prodda kerak bo'lsa keyin (mahalla domenidagi kabi)
- Realtime bildirishnoma / websocket
- Obyekt bo'yicha GIS nuqta/kontur — 1-bosqichda faqat tuman darajasi
- СВОД pivotlarni jadval sifatida saqlash — jonli hisoblanadi

---

## 13. Ochiq masalalar

1. **Boshqarma nomlari** — spec'dagi soha→boshqarma mapping taxminiy;
   real Xorazm viloyati boshqarma nomlari seeder'da keyin tuzatiladi
   (foydalanuvchi tasdiqladi: «shunday yozilsin, keyin tuzataman»).
2. **Prod disk hajmi** — hujjat saqlash uchun `192.168.0.252`da bo'sh joy
   deploy oldidan tekshirilishi kerak.
3. **`Туманлараро` obyektlar** (3 ta) — `district_id = null`, dashboardda
   alohida «Tumanlararo» toifasi sifatida ko'rsatiladi.

---

## 14. Sohalar taksonomiyasi va boshqarma mapping (boshlang'ich)

| # | `sectors.code` | Soha | Taxminiy boshqarma |
|---|---|---|---|
| 1 | `umumtalim_maktab` | Umumta'lim maktablari | Maktabgacha va maktab ta'limi boshqarmasi |
| 2 | `mtt` | Maktabgacha ta'lim tashkilotlari | Maktabgacha va maktab ta'limi boshqarmasi |
| 3 | `ijod_maktab` | Ijod va ixtisoslashtirilgan maktablar | Maktabgacha va maktab ta'limi boshqarmasi |
| 4 | `sogliqni_saqlash` | Sog'liqni saqlash va tibbiy-ijtimoiy muassasalar | Sog'liqni saqlash boshqarmasi |
| 5 | `sport` | Sportni rivojlantirish obyektlari | Jismoniy tarbiya va sport boshqarmasi |
| 6 | `madaniyat` | Madaniyat va san'at | Madaniyat boshqarmasi |
| 7 | `turizm` | Turizm infratuzilmasi obyektlari | Turizm boshqarmasi |
| 8 | `madaniy_meros` | Madaniy meros | Madaniy meros agentligi |
| 9 | `oliy_talim` | Oliy ta'lim muassasalari | (vazirlik tasarrufi) |
| 10 | `suv_kanalizatsiya` | Suv ta'minoti va kanalizatsiya | QUYKX boshqarmasi |
| 11 | `issiqlik` | Issiqlik ta'minoti | QUYKX boshqarmasi |
| 12 | `avtoyol` | Avtomobil yo'llari va ko'priklar | Avtomobil yo'llari boshqarmasi |
| 13 | `ichki_yol` | Ichki yo'llar va ko'chalar | Avtomobil yo'llari boshqarmasi |
| 14 | `irrigatsiya` | Irrigatsiya tarmoqlari va inshootlari | Suv xo'jaligi boshqarmasi |
| 15 | `melioratsiya` | Melioratsiya tarmoqlari va inshootlari | Suv xo'jaligi boshqarmasi |
| 16 | `ormon` | O'rmon xo'jaligi obyektlari | O'rmon xo'jaligi boshqarmasi |
| 17 | `mudofaa_huquq` | Mudofaa va huquqni muhofaza qiluvchi organlar | (IIB / Oliy sud departamenti) |
| 18 | `elektr` | Elektr ta'minoti obyektlari | «Hududiy elektr tarmoqlari» AJ |
| 19 | `maxsus_zona` | Maxsus iqtisodiy zonalar | Investitsiya boshqarmasi |
| 20 | `boshqa` | Obodonlashtirish va boshqa | Tuman hokimligi |

---

## 15. Amalga oshirish bosqichlari

Spec bitta modulni qamraydi, lekin hajmi tufayli reja 5 fazaga bo'linadi.
Har faza mustaqil sinaladi va yashil holatda tugaydi.

| Faza | Mazmun | Tugash mezoni |
|---|---|---|
| **F1. Poydevor** | `qurilish` connection + schema migratsiyasi, 12 jadval, `SystemsSeeder` += `qurilish`, `programs`/`sectors`/`organizations` seederlari, `EnsureQurilish` + `QurilishAccess` + `QurilishScope`, `/context` | Migratsiya idempotent ishlaydi; auth/RBAC testlari yashil |
| **F2. ETL** | Python ekstraktor (4 CSV), `qurilish:import` komandasi, SOATO derivatsiyasi, alias normalizatsiyasi, muddat parseri, soha tasniflagichi | 611 obyekt + bosqichlar + oylik grafik yuklandi; jamlanma `СВОД ДАСТУР` bilan mos; ikki marta import → bir xil natija |
| **F3. Obyekt CRUD + workflow** | `objects` API, `object_stages` holat mashinasi, moliya maydonlari, audit jurnali, hujjat yuklash/yuklab olish | Bosqich qoidalari majburlanadi (422); hujjat RBAC testlari yashil |
| **F4. Dashboard + hisobot** | СВОД agregatsiyalari (4 kesim), voronka, tender/shartnoma/topshirish panellari, xarita, Excel/PDF eksport | Agregatsiya manba jamlanmasi bilan mos |
| **F5. Ta'mirtalab + frontend** | `repair_needs` reyestri, `promote`, `ПАСПОРТ` jonli hisoboti; Vue SPA (barcha sahifalar) | `vue-tsc` + `vite build` yashil; smoke test o'tdi |

Backend fazalari (F1–F4) tugaguncha frontend uchun mock qatlam ishlatiladi
(sport domenidagi `lib/mock` naqshi).

---

## 16. Ish tartibi

- **Local-first:** hamma narsa avval lokalda ishlaydi va sinaladi.
- **Deploy FAQAT aniq buyruq bilan.** Lokal commit ruxsat, auto-push yo'q.
- Deploy vaqti kelganda: `sudo -u www-data` git/composer/artisan;
  yangi route uchun `php artisan optimize` shart;
  `FRONTEND_ORIGINS` + `SANCTUM_STATEFUL_DOMAINS`ga `qurilish.digital-xorazm.uz`.
