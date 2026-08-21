# «Yoshlar ishlari monitoring va ijro platformasi» — F1 (poydevor) dizayni

**Sana:** 2026-08-21
**Domen:** `yoshlar` (yangi)
**Holat:** DIZAYN TASDIQLANDI — implementatsiya boshlanmagan
**Manba TZ:** `D:\kadr\yoshlar-TZ.md` (v1.0)
**Qamrov:** F1 — schema + markaziy auth + RBAC/scope + tashkilot/xodim + yoshlar
reyestri + hisob yaratish. F2–F5 alohida spec bilan keladi.

---

## 1. Maqsad

Xorazm viloyati yoshlar ishlari vertikali va sektoral boshqarmalar faoliyatini
yagona raqamli tizimda nazorat qilish. `digital-xorazm` ekotizimining navbatdagi
moduli: markaziy PostgreSQL (`kbt` DB, yangi `yoshlar` schema) va markaziy
Sanctum SPA autentifikatsiyasi.

**F1 ning yagona vazifasi** — keyingi barcha modullar (topshiriq, muammo, otaliq,
bandlik) suyanadigan poydevorni qurish: kim kim ekani (tashkilot + xodim + rol +
doira) va yoshlar reyestri. F1 tugagach tizimga kirish mumkin, reyestr yuritiladi,
lekin hali hech qanday ish jarayoni (workflow) yo'q.

---

## 2. TZ tahlili — hal qilingan 6 masala

TZ v1.0 puxta, lekin quyidagi nuqtalar dizayn bosqichida hal qilindi
(2026-08-21, foydalanuvchi qarori bilan):

| # | Masala | Qaror |
|---|---|---|
| 1 | 5.5-da bandlik zanjirida «tuman soliq»/«viloyat soliq» bor, lekin 3-bo'limdagi 6 rol ichida soliq roli YO'Q | Soliq — alohida rol emas, **sektoral vertikalning sektori**: tuman soliq = `sektor_bolim` + `sector=soliq`; viloyat soliq = `sektor_boshqarma` + `sector=soliq`. Rol soni 6 ta qoladi; zanjir rolga emas, `organizations.type` + `sector_id` juftligiga bog'lanadi |
| 2 | 3-bo'limda `yoshlar_boshqarma` «birinchi tasdiqlash», 5.6-da esa zanjir oxirida turibdi | 5.6 to'g'ri: `yoshlar_bolim` = **tuman (birinchi) tasdiq**, `yoshlar_boshqarma` = **yakuniy tasdiq** |
| 3 | 8-matritsada `sektor_bolim` reyestrda «RW (o'z)» — «o'z» aniq emas | Reyestrga **yoshlar vertikali egalik qiladi**. `yoshlar_bolim` o'z tumanida to'liq RW; `sektor_bolim` ko'radi va yangi yosh **taklif** qiladi (`verification_status=pending`), mavjud yozuvni tahrirlamaydi |
| 4 | `yoshlar_admin` ham hisob ochadi, ham topshiriq biriktiradi — vakolatlar to'planishi | Qurilish saboqi (`qurilish_moderator` ajratilgani): **admin tasdiqlash zanjirida qatnashmaydi**. F1'da admin `youth.verify` ruxsatiga ega EMAS; tasdiq faqat `yoshlar_bolim`/`yoshlar_boshqarma`da |
| 5 | Yosh chegarasi 14–30 dinamik: bugungi yosh ertaga chiqib ketadi | Yozuv o'chirilmaydi. `registry_status` (`active`/`archived_age`/`moved`/`deceased`) + `yoshlar:refresh-registry` buyrug'i. Yosh ustun sifatida SAQLANMAYDI — `birth_date`dan hisoblanadi |
| 6 | 5.3-da muammo manbai «murojaat» — lekin `murojaat` alohida domen | F1'da bog'lanmaydi. F4'da faqat havola (`source_ref`) sifatida; cross-schema FK yo'q (ekotizim qoidasi) |

### Foydalanuvchi qarorlari (2026-08-21)

1. **Reyestr egaligi:** yoshlar vertikali (yuqoridagi 3-qator).
2. **Soliq:** sektor sifatida, 6 rol qoladi (1-qator).
3. **Boshlang'ich ma'lumot:** bo'sh reyestr, qo'lda kiritish. Import F1 doirasidan
   tashqarida (`import_sessions` jadvali ham F1'da yaratilmaydi).
4. **Til:** lotin + kirill almashtirgich (7-bo'limga qarang).

---

## 3. Ekotizim naqshlari — nimadan nusxa olinadi

| Element | Manba | Yoshlarda |
|---|---|---|
| Schema izolyatsiyasi | `2026_08_13_100000_create_qurilish_schema.php` | `create_yoshlar_schema` — `CREATE SCHEMA IF NOT EXISTS`, jadval mavjud bo'lsa skip (idempotent, forward-only), faqat pgsql |
| Ulanish | `config/database.php:184` (qurilish) | `'yoshlar'` ulanishi, `search_path=yoshlar,master,public` |
| Geo | `qurilish.objects.district_id/mahalla_id` | `youth.mahalla_id` -> `master.mahallas` (509), `district_id` -> `master.districts` (13). uuid + index, **FK yo'q** |
| RBAC | `QurilishAccess` | `YoshlarAccess` — rol `auth.user_system_access.role` dan, ruxsat kod xaritasidan |
| Scope | `QurilishScope` (profil yo'q -> `whereRaw('1=0')`) | `YoshlarScope` — **ikki o'lchov** (geo + org subtree), fail-closed |
| Gvardiya | `EnsureQurilish` | `EnsureYoshlar` (rolsiz -> 403) |
| Kontekst | `/api/qurilish/context` | `/api/yoshlar/context` — bir marta chaqiriladi |
| Hisob | `qurilish:make-user` | `yoshlar:make-user` — parol argumentda emas; **avval `staff`, keyin `user_system_access`** |
| PII | `Hr\Models\Employee`: `encrypted` cast + `$hidden` + HMAC `jshshir_hash` | `youth.pinfl` + `pinfl_hash` + `pii_access_log` |
| Transliteratsiya | `Mahalla\Support\UzbekTransliterator`, `Sport\Support\Translit` | `Yoshlar\Support\Translit` (lotin->kirill, ko'rsatish uchun) |
| Frontend | `D:\kadr\qurilish` (Vue 3.5, TS, Pinia, Tailwind v4, Vite 8, vitest) | `D:\kadr\yoshlar` — aynan shu stack, alohida git repo |

---

## 4. Ma'lumot modeli — `yoshlar` schema (F1: 6 jadval)

Barcha PK — `uuid`. Barcha jadvalda `timestamps`. Cross-schema FK YO'Q
(`master.*`, `auth.*` ga faqat uuid + index).

### 4.1 `sectors` — sektoral yo'nalishlar spravochnigi

| Ustun | Tur | Izoh |
|---|---|---|
| `id` | uuid PK | |
| `code` | varchar(40) UNIQUE | `bandlik`, `talim`, `soliq`… |
| `name_cyr` | varchar(300) | |
| `name_lat` | varchar(300) | |
| `sort_order` | int default 0 | |
| `is_active` | bool default true | |

**Seed (9 ta):** `bandlik`, `talim`, `oliy_talim`, `sogliq`, `soliq`, `iib`,
`madaniyat`, `sport`, `mahalla_oila`. Qolganini admin UI orqali qo'shadi.

### 4.2 `organizations` — ikki vertikal yagona reyestrda

| Ustun | Tur | Izoh |
|---|---|---|
| `id` | uuid PK | |
| `type` | varchar(20) index | `viloyat_yoshlar` / `tuman_yoshlar` / `viloyat_sektor` / `tuman_sektor` |
| `parent_id` | uuid nullable index | tuman tashkiloti -> viloyat tashkiloti |
| `district_id` | uuid nullable index | `master.districts`; `tuman_*` uchun MAJBURIY |
| `sector_id` | uuid nullable index | `sectors`; `*_sektor` uchun MAJBURIY |
| `name_cyr`, `name_lat` | varchar(500) | |
| `short_name` | varchar(200) nullable | |
| `is_active` | bool default true | |

**Yaxlitlik qoidalari** (Service darajasida, DB CHECK emas — moslashuvchanlik uchun):

- `type LIKE 'tuman_%'` -> `district_id` majburiy, `parent_id` majburiy;
- `type LIKE '%_sektor'` -> `sector_id` majburiy;
- `viloyat_yoshlar` — seed'da 1 ta (DB cheklovi qo'yilmaydi);
- `parent_id` daraja mosligi: tuman tashkilotining otasi viloyat darajasida va
  (sektor bo'lsa) **bir xil `sector_id`** bo'lishi shart.

**Seed:** 1 ta viloyat yoshlar boshqarmasi + 13 ta tuman yoshlar bo'limi
(`master.districts` dan). Sektor tashkilotlari admin tomonidan kiritiladi.

### 4.3 `staff` — foydalanuvchi profili (scope manbai)

| Ustun | Tur | Izoh |
|---|---|---|
| `id` | uuid PK | |
| `user_id` | uuid index | `auth.users.id` |
| `org_id` | uuid index | `organizations.id` |
| `position` | varchar(200) nullable | lavozim |
| `can_patronage` | bool default false | otaliq huquqi (F4'da ishlatiladi, F1'da saqlanadi) |
| `is_active` | bool default true | |

**Cheklov:** `UNIQUE (user_id) WHERE is_active` — bitta faol foydalanuvchi bitta
tashkilotda (partial unique index). Aks holda `YoshlarScope` qaysi tashkilotni
olishini bilmay qoladi.

### 4.4 `youth` — yoshlar reyestri (poydevor jadval)

| Ustun | Tur | Izoh |
|---|---|---|
| `id` | uuid PK | |
| `last_name`, `first_name`, `middle_name` | varchar(120) | **lotinda** saqlanadi |
| `full_name_norm` | text | `UPPER(lotin FIO)`, GIN trigram indeks — qidiruv uchun |
| `birth_date` | date index | yosh shundan hisoblanadi |
| `gender` | varchar(10) | `erkak` / `ayol` |
| `district_id` | uuid index | `master.districts` |
| `mahalla_id` | uuid index | `master.mahallas` |
| `address` | text nullable | ko'cha/uy |
| `phone` | varchar(30) nullable | |
| `pinfl` | text nullable | **encrypted cast + `$hidden`** |
| `pinfl_hash` | varchar(64) nullable UNIQUE | HMAC-SHA256, dublikat to'sig'i |
| `passport_series` | text nullable | encrypted + hidden |
| `passport_number` | text nullable | encrypted + hidden |
| `education_status` | varchar(30) | `maktab` / `kollej` / `otm` / `bitiruvchi` / `oqimaydi` |
| `education_place` | varchar(300) nullable | |
| `employment_status` | varchar(30) | `band` / `band_emas` / `oqiydi` / `tadbirkor` / `migratsiya` |
| `workplace` | varchar(300) nullable | |
| `is_neet` | bool default false | o'qimaydi va ishlamaydi |
| `is_graduate_unemployed` | bool default false | bitiruvchi-ishsiz |
| `in_patronage` | bool default false | F4 avtomatik yangilaydi |
| `has_open_case` | bool default false | F4 avtomatik yangilaydi |
| `in_youth_book` | bool default false | «Yoshlar daftari»da |
| `is_entrepreneur` | bool default false | |
| `registry_status` | varchar(20) index default `active` | `active` / `archived_age` / `moved` / `deceased` |
| `verification_status` | varchar(20) index default `verified` | `pending` / `verified` / `rejected` |
| `verified_by` / `verified_at` / `reject_reason` | uuid / timestamp / text | tasdiqlash izi |
| `created_by_org_id` | uuid index | kim kiritgan tashkilot |
| `created_by`, `updated_by` | uuid nullable | `auth.users.id` |
| `deleted_at` | timestamp | SoftDeletes |

**Indekslar:** `(district_id)`, `(mahalla_id)`, `(birth_date)`,
`(registry_status, district_id)`, `(verification_status)`,
`full_name_norm` (quyida), partial UNIQUE `pinfl_hash` (NOT NULL bo'lganda).

**FIO qidiruv indeksi:** `pg_trgm` kengaytmasi lokal bazada allaqachon o'rnatilgan
(`pg_extension`da bor — mahalla moduli ishlatadi). Migratsiya
`CREATE EXTENSION IF NOT EXISTS pg_trgm` ni **xatoni yutib** bajaradi (prodda
huquq yetmasligi mumkin); kengaytma bor bo'lsa GIN trigram indeks
(«ichidan» qidiruv), bo'lmasa `text_pattern_ops` btree (faqat prefiks qidiruv)
yaratiladi. Ikkala holatda ham qidiruv ishlaydi — farq tezlik va «ichidan»
moslikda.

**Yosh filtri:** `age` ustun sifatida saqlanmaydi va `GENERATED` ham qilinmaydi —
PostgreSQL'da `age()` `STABLE` (immutable emas), generated ustunga yaramaydi.
Filtr sana oralig'iga aylantiriladi:
`birth_date BETWEEN (bugun - 31 yil + 1 kun) AND (bugun - 14 yil)` —
`birth_date` indeksi ishlaydi, hisoblash tekin.

**Dublikat siyosati:** `pinfl` ixtiyoriy (qo'lda kiritishda har doim ma'lum emas).
Berilgan bo'lsa — `pinfl_hash` orqali qat'iy unique. Berilmagan bo'lsa — saqlashdan
oldin `full_name_norm + birth_date + mahalla_id` bo'yicha **ogohlantirish**
(«ehtimoliy dublikat, baribir saqlansinmi?»), bloklamaydi.

### 4.5 `audit_log` — umumiy audit

`id` uuid PK, `user_id` uuid index, `action` varchar(60), `entity_type` varchar(40),
`entity_id` uuid index, `changes` jsonb, `ip` varchar(45), `created_at` timestamp index.

Yoziladi: youth create/update/delete/verify/reject, organization/staff CRUD,
hisob ochish/o'chirish. `changes` — faqat o'zgargan maydonlar (`eski -> yangi`),
**PII maydonlari qiymati yozilmaydi** (faqat «pinfl o'zgardi» fakti).

### 4.6 `pii_access_log` — maxfiy maydon ochilishi

`id` uuid PK, `user_id` uuid index, `youth_id` uuid index, `fields` varchar(120)
(`pinfl,passport`), `ip` varchar(45), `created_at` timestamp index.

Har `reveal-pii` chaqiruvi uchun bitta yozuv. Bu jadval **hech qachon o'chirilmaydi**.

---

## 5. RBAC — 6 rol

`YoshlarAccess::SYSTEM_CODE = 'yoshlar'`; rol `auth.user_system_access.role` dan
(`is_active=true` va `systems.code='yoshlar'`), ruxsat kod xaritasidan.

| Ruxsat | hokim_orin | admin | yoshlar_boshq | yoshlar_bolim | sektor_boshq | sektor_bolim |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| `yoshlar.view` | ✔ viloyat | ✔ | ✔ viloyat | ✔ tuman | ✔ viloyat¹ | ✔ o'z tumani |
| `yoshlar.export` | ✔ | ✔ | ✔ | ✔ | ✔ | — |
| `yoshlar.youth.create` | — | ✔ | — | ✔ | — | ✔ (pending) |
| `yoshlar.youth.update` | — | ✔ | — | ✔ | — | ✔ faqat o'z `pending`² |
| `yoshlar.youth.delete` | — | ✔ | — | — | — | — |
| `yoshlar.youth.verify` | — | **—** | ✔ | ✔ | — | — |
| `yoshlar.pii.reveal` | **—** | ✔ | ✔ | ✔ | — | — |
| `yoshlar.org.manage` | — | ✔ | — | — | — | — |
| `yoshlar.staff.manage` | — | ✔ | — | — | — | — |
| `yoshlar.user.manage` | — | ✔ | — | — | — | — |
| `yoshlar.audit.view` | — | ✔ | ✔ | — | — | — |

¹ **Reyestrda `sektor_boshqarma` butun viloyatni ko'radi**, chunki yosh mahallaga
tegishli, sektorga emas — «org subtree» cheklovi reyestrga ma'noga ega emas.
Org subtree o'lchovi F2+ da (topshiriq, case, otaliq) ishlatiladi.

² `sektor_bolim` **o'zi yaratgan va hali `pending`/`rejected` holatidagi** yozuvni
tahrirlashi mumkin (rad etilgach tuzatib qayta yuborish uchun). Yozuv `verified`
bo'lgach — u uchun faqat o'qish; keyingi tuzatishni `yoshlar_bolim` kiritadi.

**Ikki ataylab qilingan qaror:**

1. **Hokim o'rinbosari PINFL ko'rmaydi.** PII lavozimga emas, ish zaruratiga
   beriladi (need-to-know): rahbariyat agregat ko'radi, shaxsni emas.
2. **Admin tasdiqlay olmaydi** (`youth.verify` yo'q). Hisob ochuvchi odam ayni
   paytda tasdiqlay olsa, o'ziga hisob ochib, o'zi kiritib, o'zi tasdiqlaydi —
   nazorat sikli soxta bo'ladi (qurilish domenidagi `moderator` ajratilishi bilan
   bir xil sabab).

---

## 6. Scope — ikki o'lchovli, fail-closed

```
YoshlarScope::districtIds(User): ?array   // null = butun viloyat (cheklovsiz)
YoshlarScope::orgIds(User): ?array        // null = barcha tashkilot
YoshlarScope::applyYouth(Builder, User): Builder
```

**Algoritm:**

| Rol | `districtIds` | `orgIds` |
|---|---|---|
| `yoshlar_admin` | null | null |
| `yoshlar_hokim_orinbosari` | null | null |
| `yoshlar_boshqarma` | null | null |
| `yoshlar_bolim` | `[staff.org.district_id]` | `[staff.org_id]` |
| `sektor_boshqarma` | null | `[staff.org_id]` + bolalari (`parent_id` bo'yicha 1 daraja) |
| `sektor_bolim` | `[staff.org.district_id]` | `[staff.org_id]` |

**Fail-closed:** faol `staff` yozuvi yo'q bo'lsa yoki `org` nofaol bo'lsa —
`applyYouth` `whereRaw('1 = 0')` qaytaradi (hech narsa emas, hammasi emas).
Bu qurilish domenidagi eng muhim xavfsizlik naqshi.

**Qaysi o'lchov qayerda:**

- **Reyestr (`youth`)** — geo o'lchov (yosh mahallaga tegishli, sektorga emas).
- **Topshiriq/case (F2+)** — org o'lchov.
- `sektor_bolim` uchun ko'rish geo bo'yicha, lekin **yozish** faqat o'zi yaratgan
  `pending` yozuvlarda.

**Ko'rinish filtri:** `verification_status='pending'` yozuvlar reyestr
statistikasiga va eksportga KIRMAYDI; ular faqat «Tasdiq kutmoqda» navbatida
va yaratuvchi tashkilotga ko'rinadi.

---

## 7. Til — lotin manba + kirill rejimi

**Nega bu yo'l:** o'zbek lotin->kirill transliteratsiyasi deterministik
(`sh->ш`, `oʻ->ў`, `gʻ->ғ`), teskarisi noaniq (`ц` -> `ts`/`s`). Shuning uchun
lotin — yagona manba, kirill runtime'da hosil bo'ladi. Tarjima lug'ati ikkilanmaydi.

- **Spravochniklar** (`sectors`, `organizations`): `name_cyr` + `name_lat`
  ikkalasi saqlanadi (qurilish naqshi). Admin lotin kiritsa, kirill avtomatik
  to'ldiriladi (keyin tahrirlash mumkin).
- **Geo** (`master.mahallas/districts`): ikkala ustun allaqachon bor — til
  rejimi shunchaki ustunni tanlaydi, transliteratsiya kerak emas.
- **Yoshlar F.I.Sh:** lotinda saqlanadi; kirill rejimida `toCyr()` bilan ko'rsatiladi.
- **Qidiruv:** so'rov kirillda bo'lsa lotinga o'giriladi va `full_name_norm`
  (UPPER, trigram) bo'yicha qidiriladi — ikkala yozuvda ham topadi.
- **UI matnlari:** lotin manba + frontend `toCyr()` utili; `useLocale()` store
  (`lat|cyr`, localStorage'da saqlanadi). API javobi til rejimiga bog'liq emas.
- Backend `Yoshlar\Support\Translit` — server tomonda (eksport, seed) shu ish uchun.

---

## 8. Hisob yaratish

```
php artisan yoshlar:make-user {login} {name} {role}
    --org=<nom yoki ID>  --position=<lavozim>  --can-patronage
```

**Qoidalar** (qurilish buyrug'idan olingan):

- Parol argument sifatida QABUL QILINMAYDI (shell tarixiga tushmasin) —
  ichida generatsiya qilinadi va **bir marta** ko'rsatiladi.
- `User::withTrashed()` bilan login bandligi tekshiriladi (`users.login` UNIQUE
  soft-delete qatorlarni ham hisoblaydi).
- **`--org` majburiy** — `yoshlar_hokim_orinbosari` va `yoshlar_admin` bundan
  mustasno. Usiz foydalanuvchi fail-closed tuzoqqa tushadi va hech narsa ko'rmaydi.
- Rol <-> tashkilot turi mosligi tekshiriladi:
  `yoshlar_boshqarma` -> `viloyat_yoshlar`; `yoshlar_bolim` -> `tuman_yoshlar`;
  `sektor_boshqarma` -> `viloyat_sektor`; `sektor_bolim` -> `tuman_sektor`.
- **Tartib:** avval `staff` (profil), keyin `auth.user_system_access` (kirish
  huquqi). Ikki ulanish bitta tranzaksiyada emas — teskarisi bo'lsa, profilsiz-u
  kira oladigan hisob qolib ketardi.

Admin UI (`/admin/users`) shu servisning ustidan ishlaydi (qurilish
`UserAdminService` naqshi): hisob ochish, faolsizlantirish, parolni tiklash.

### 8.1 Ikkinchi konsol buyrug'i — reyestr yoshini yangilash

```
php artisan yoshlar:refresh-registry [--dry-run]
```

31 yoshga to'lgan `active` yozuvlarni `archived_age` ga o'tkazadi (o'chirmaydi —
tarix va KPI saqlanadi). Idempotent; `--dry-run` faqat sonini ko'rsatadi.
F1 doirasida, chunki `registry_status` semantikasi shu buyruqsiz o'lik ustun
bo'lib qoladi. Kelajakda `schedule` ga (kuniga bir marta) ulanadi.

---

## 9. API (F1)

```
GET    /api/yoshlar/context                 rol, ruxsat, scope, spravochniklar, badge
GET    /api/yoshlar/youth                   filtr: district, mahalla, yosh oralig'i, jins,
                                            education_status, employment_status, NEET,
                                            registry_status, verification_status, qidiruv
POST   /api/yoshlar/youth                   yangi yosh (rolga qarab verified|pending)
GET    /api/yoshlar/youth/{id}
PATCH  /api/yoshlar/youth/{id}
DELETE /api/yoshlar/youth/{id}              soft delete (admin)
POST   /api/yoshlar/youth/{id}/verify       tasdiq (bolim/boshqarma)
POST   /api/yoshlar/youth/{id}/reject       {reason} — qayta ishlashga
POST   /api/yoshlar/youth/{id}/reveal-pii   -> pii_access_log + audit_log
GET    /api/yoshlar/youth/stats             mahalla/tuman kesimi, NEET, yosh piramidasi
GET    /api/yoshlar/organizations           POST / PATCH  (admin)
GET    /api/yoshlar/sectors                 POST / PATCH  (admin)
GET    /api/yoshlar/staff                   POST / PATCH  (admin)
GET    /api/yoshlar/admin/users             POST / PATCH  (admin)
GET    /api/yoshlar/audit                   (admin, yoshlar_boshqarma)
```

Barcha marshrutlar: `Route::middleware(['auth:sanctum', 'yoshlar'])->prefix('yoshlar')`.
`routes/api/yoshlar.php` -> `routes/api.php` ga `require`.

**`/context` javobi:** `user`, `role`, `role_name`, `permissions`,
`sees_everything`, `scope {org_id, org_name, org_type, district_id, sector_id}`,
`badges {pending_youth}`, `reference {districts, mahallas, sectors, organizations,
education_statuses, employment_statuses}`.

---

## 10. Frontend — `D:\kadr\yoshlar` (yangi git repo)

Stack: Vue 3.5 + TS + Pinia + Vue Router + Tailwind v4 + Vite 8 + vitest
(qurilish `package.json` naqshi). Sanctum axios `withCredentials`.

**F1 sahifalari:**

1. `Login` — markaziy auth (CSRF + `/api/login`), xato va sessiya tugashi ishlovi.
2. `AppLayout` — rolga qarab menyu, til almashtirgich (lat/cyr), foydalanuvchi paneli.
3. `Reyestr` — jadval (server-side pagination), filtr paneli, qidiruv, eksport tugmasi.
4. `YoshKartochkasi` — ko'rish/tahrirlash formasi, PII «ko'rsatish» tugmasi
   (ruxsat bo'lsa; bosilganda jurnalga yozilishi haqida ogohlantirish).
5. `TasdiqNavbati` — `pending` yozuvlar (bolim/boshqarma).
6. `Tashkilotlar` / `Xodimlar` / `Hisoblar` — admin sahifalari.
7. `Dashboard` skeleti — F1'da faqat reyestr KPIlari (jami, NEET, tasdiq kutayotgan,
   tuman kesimi); F2'da to'ladi.

---

## 11. Testlar — F1 «tayyor» mezoni

**Feature (backend):**

1. Rolsiz foydalanuvchi `/api/yoshlar/*` -> **403**; auth'siz -> **401**.
2. `staff` yozuvi yo'q rol -> reyestr **bo'sh** qaytadi (hammasi emas) — fail-closed.
3. `yoshlar_bolim` (Xiva) Urganch yoshini ko'rmaydi; `PATCH` -> 403/404 (IDOR).
4. `sektor_bolim` yaratgan yozuv `verification_status=pending` bo'ladi va
   umumiy reyestr ro'yxatiga/statistikaga kirmaydi.
5. `sektor_bolim` mavjud yozuvni tahrirlay olmaydi -> 403.
6. `yoshlar_admin` `verify` qila olmaydi -> 403 (vakolatlar bo'linishi).
7. `pii.reveal` ruxsati yo'q rolning JSON javobida `pinfl`/`passport_*`
   **umuman yo'q** (`$hidden`) — ochiq matn ham, shifrmatn ham.
8. `reveal-pii` -> `pii_access_log`da yangi yozuv; javobda ochiq qiymat.
9. Bir xil PINFL bilan ikkinchi yosh yaratib bo'lmaydi (`pinfl_hash` unique).
10. PINFLsiz, lekin bir xil FIO+sana+mahalla -> ogohlantirish qaytadi, saqlash
    bloklanmaydi.
11. `yoshlar:make-user` tuman roliga `--org`siz -> xato; rol/tashkilot turi
    mos kelmasa -> xato.
12. Yosh oralig'i filtri chegaralarda to'g'ri ishlaydi (14 va 30 yosh).
13. Audit: youth update -> `audit_log`da o'zgargan maydonlar, PII qiymatisiz.

**Frontend:** `vue-tsc -b` (typecheck) + `vitest run` + `vite build` — uchalasi yashil.
Til almashtirgich uchun unit test: `toCyr()` asosiy holatlar.

---

## 12. F1 doirasidan TASHQARI (ataylab)

Protokol/topshiriq, muddat ogohlantirish, case management, otaliq, bandlik va
3-tomonlama zanjir, umumiy tasdiqlash dvigateli (`approval_chains`), hujjat/media
boshqaruvi, in-app bildirishnoma, executive dashboard, Excel/PDF eksport,
Excel import (`import_sessions`) — **F2–F5**.

F1 ularni faqat **qabul qilishga tayyor** qiladi: `organizations.type + sector_id`
zanjir uchun, `staff.can_patronage` otaliq uchun, `youth` flaglari avtomatik
yangilanish uchun, `audit_log` umumiy jurnal sifatida.

---

## 13. Ish tartibi

- **Local-first:** hammasi lokalda ishlaydi va sinaladi.
- **Deploy FAQAT topshiriq bilan** (auto-push YO'Q; lokal commit ruxsat).
- Deploy vaqti kelganda: prod `www-data`, yangi route -> `php artisan optimize`,
  yangi subdomen `yoshlar.digital-xorazm.uz` -> `FRONTEND_ORIGINS` +
  `SANCTUM_STATEFUL_DOMAINS` + nginx + TLS.
