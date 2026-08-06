# «Свод жадваллар» (Monitoring) moduli — HANDOFF / kontekst brief

> Maqsad: bu modulni **alohida Claude tabда** davom ettirish. Ushbu hujjat modulning
> to'liq holatini (backend + frontend + prod) bir joyda beradi. Tafsilot kerak bo'lsa —
> ko'rsatilgan fayllarni o'qing. Sana: 2026-08-06. Oxirgi backend commit: `2c0ac23`.

---

## 1. Modul nima qiladi

**Qaror/farmon ijrosi monitoringi** («Свод жадваллар»). Har **svod** = bitta qaror/farmon.
Svodning **og'irlikли ustunlari** (ko'rsatkichlar) bor; har **tuman** o'z satrida har ustun
uchun **foiz (0–100)** kiritadi. **UMUMIY ТАЙЁРЛИК = Σ(value×weight) / Σ(weight)**.
Ijro holati foizdан avtomatik: `0 → not_started`, `1–(threshold-1) → in_progress`,
`≥ completion_threshold → done`. Namuna og'irliklar Excel svodiдан: **20 / 35 / 45**.

Oqim: **tuman kiritadi → ЮБОРАДИ (submit) → viloyat ТАСДИҚЛАЙДИ (confirm) / ҚАЙТАРАДИ (return)**.
Tuman **butun 13-tuman svodini ko'radi** (shaffoflik), lekin **faqat o'z satrini** tahrirlaydi.
Excel eksport (App\Support\SimpleXlsx), svod nusxalash (duplicate/shablon), statistika.
**Joriy holat modeli** (tarix yo'q — oxirgi qiymat saqlanadi).

Spec: `D:\kadr\platform\docs\svod-jadvallar-spec-2026-08-04.md`.

---

## 2. Muhit (environment)

| | |
|---|---|
| Backend | `D:\kadr\platform` — Laravel 11, **PHP 8.4 local** (`C:\php84\php.exe`), 8.5 prod |
| Frontend | `D:\kadr\advisor` — Vue 3.5 + TS + Pinia (options) + Tailwind v4 + Vite (**bun**), dev port **5174** |
| DB | PostgreSQL — local `kbt`, prod `xorazm`; **schema: `advisor`** (search_path advisor,master,public) |
| Auth | markaziy Sanctum SPA cookie (`POST /api/login`); rollar: `advisor_viloyat` / `advisor_bolinma` / `advisor_tuman` |
| Tumanlar | `master.districts` (Xorazm 13) — cross-schema FK YO'Q, uuid + index |
| Prod | SPA https://advisor.digital-xorazm.uz · API https://app.digital-xorazm.uz · Cloudflare proxied |

---

## 3. Ma'lumotlar modeli (4 jadval — advisor schema)

Migratsiya: `database/migrations/2026_08_04_110000_create_advisor_monitoring.php`
(pgsql-only, idempotent — mavjud jadval bo'lsa skip). **Prodда bajarilgan.**

- **monitoring_sheets** — svod (qaror/farmon): `title, basis, reference_no, reference_date,
  category(qaror|farmon|pq|farmoyish|other), as_of_date, completion_threshold(default 100),
  status(active|archived), created_by`, timestamps, **softDeletes**.
- **monitoring_metrics** — og'irlikли ustun: `sheet_id, name, unit(default '%'), weight(decimal 6,2), sort_order`.
- **monitoring_entries** — (svod × tuman) satri + tasdiq oqimi: `sheet_id, district_id, note,
  review_status(draft|submitted|confirmed|returned), submitted_at/by, confirmed_at/by,
  return_comment, updated_by`. **unique(sheet_id, district_id)**.
- **monitoring_values** — (entry × ustun) qiymati: `entry_id, metric_id, value(0..100)`.
  **unique(entry_id, metric_id)**.

Modellar: `app/Domains/Advisor/Models/{MonitoringSheet,MonitoringMetric,MonitoringEntry,MonitoringValue}.php`
(barchasi `connection = 'advisor'`, HasUuids).

---

## 4. Backend (app/Domains/Advisor)

**Service** `Services/MonitoringService.php` — asosiy mantiq:
`listSheets(filters)`, `createSheet(data,metrics,userId)`, `syncMetrics(sheet,metrics)`,
`sheetDetail(sheet)`, `upsertEntry(...)`, `confirmEntry(sheet,districtId,userId)`,
`returnEntry(sheet,districtId,comment,userId)`, `duplicateSheet(sheet,userId)`,
`updateSheet(sheet,data,metrics?)`, `exportGrid(sheet)`, `stats(scope)`.
Yordamchilar: `overallFor(valueMap,metrics)` (weighted), `execStatus(overall,threshold)`,
`presentSheet`, `valueMapForEntries`, `metricsBySheet`, `districts`, `districtCount`, `hasEntries`.

**Controller** `Http/Controllers/Api/MonitoringController.php`:
`index, store, stats, show, update, export, duplicate, entry, confirm, return`.

**Routes** `routes/api/advisor.php` (auth:sanctum + EnsureAdvisor):
```
GET    /api/advisor/monitoring                                  index
POST   /api/advisor/monitoring                                  store        (viloyat)
GET    /api/advisor/monitoring/stats                            stats
GET    /api/advisor/monitoring/{sheet}                          show
PATCH  /api/advisor/monitoring/{sheet}                          update       (viloyat)
GET    /api/advisor/monitoring/{sheet}/export                   export       (xlsx)
POST   /api/advisor/monitoring/{sheet}/duplicate               duplicate    (viloyat)
POST   /api/advisor/monitoring/{sheet}/entry                    entry        (tuman: o'z tumani)
POST   /api/advisor/monitoring/{sheet}/entries/{district}/confirm   confirm  (viloyat)
POST   /api/advisor/monitoring/{sheet}/entries/{district}/return    return   (viloyat)
```
> Diqqat: `/monitoring/stats` `{sheet}` binding'дан OLDIN turadi (route to'qnashuvi).

**Ruxsatlar** `Support/AdvisorAccess.php` (PERMISSIONS):
- `advisor_viloyat` = `['*']` — hammasi (view/enter/manage/confirm; svod yaratadi/tahrirlaydi/tasdiqlaydi).
- `advisor_bolinma` = `monitoring.view` (faqat ko'radi).
- `advisor_tuman` = `monitoring.view`, `monitoring.enter` (o'z tumani satrini kiritadi/yuboradi).
Permission kalitlari: `monitoring.view / monitoring.enter / monitoring.manage / monitoring.confirm`.

**Test** `tests/Feature/Advisor/MonitoringTest.php` — weighted 23.5 tasdiqланган, oqim + RBAC.
Ishga tushirish: `cd D:\kadr\platform && C:\php84\php.exe artisan test --filter=Monitoring`.

---

## 5. Frontend (D:\kadr\advisor/src)

- **Nav** `layouts/AppLayout.vue`: «Свод жадваллар» → `/monitoring`, icon `table`, name `monitoring` (barcha rol).
- **Router** `router/index.ts`: `/monitoring` → `pages/Monitoring.vue`; `/monitoring/:id` → `pages/MonitoringDetail.vue`.
- **Pages**:
  - `pages/Monitoring.vue` — ro'yxat + statistika + filtr + «Янги свод» modal.
  - `pages/MonitoringDetail.vue` — Excel-ko'rinish svod grid: rangли ijro holati qatorlar,
    inline tuman kiritish + Юбориш, viloyat Тасдиқлаш/Қайтариш modal, JAMI/O'RTACHA, Excel/Нусха tugmalari.
- **Component** `components/monitoring/MonitoringCreateModal.vue` — og'irlikли dinamik ustunlar.
- **Store** `stores/monitoring.ts` (Pinia options): `fetchSheets, fetchStats, fetchSheet,
  createSheet, saveEntry, confirm, returnEntry, duplicate, exportSheet`.
- **Lib** `lib/monitoring.ts`: `EXEC_META/execMeta`, `REVIEW_META/reviewMeta`,
  `CATEGORY_LABELS/categoryLabel`, `CATEGORY_ORDER`, `pct`.
- **Types** `types/index.ts` (monitoring bloki): `MonitoringCategory, MonitoringExecStatus,
  MonitoringReviewStatus, MonitoringMetric, MonitoringListItem, MonitoringSheetMeta,
  MonitoringCell, MonitoringRow, MonitoringDetail, MonitoringCreatePayload,
  MonitoringEntryPayload, MonitoringStats`.

Ishga tushirish (local): backend `cd D:\kadr\platform && C:\php84\php.exe artisan serve --port=8020`;
frontend `cd D:\kadr\advisor && bun run dev` (:5174). Login: `advisor_viloyat` / `advisor_<tuman>`
(parol `.env` ADVISOR_SEED_PASSWORD; prod parollar `D:\kadr\xbt\.credentials.local.txt`).
Typecheck: `bun run typecheck`; build: `bun run build`.

---

## 6. Joriy holat (prod)

- Modul **JONLI** — nav «Свод жадваллар», backend routelar, migratsiya prodда bajarilgan.
- Ilk deploy: commit `7b213a5` (frontend index-Ce81wMWX); keyin butun SPA qayta build qilinган.
- **Prodда 0 ta svod** (E2E test svodlari psql bilan tozalanган — prod toza).
- Excel og'irlik teskari-muhandislik: 20/35/45.

---

## 7. Konventsiyalar / TUZOQLAR (majburiy)

- **Local-first + deploy FAQAT buyruq bilan**: avval localда qur+test+build; git push va deploy
  FAQAT foydalanuvchi «deploy qil» deganда. Lokal commit ruxsat.
- **Deploy oqimi** (update deploy — SEED YO'Q):
  1. `cd D:\kadr\platform && git push origin main`
  2. ssh: `ssh -i ~/.ssh/kbt_deploy xbt@192.168.0.252`
     `cd /var/www/app; sudo -u www-data git fetch origin; sudo -u www-data git reset --hard origin/main;`
     `sudo -u www-data php artisan migrate --force; ... config:cache; ... route:cache; sudo systemctl restart php8.5-fpm`
  3. frontend: `cd D:\kadr\advisor && bun run build; tar -czf /tmp/advisor-dist.tgz -C dist .;`
     `scp -i ~/.ssh/kbt_deploy /tmp/advisor-dist.tgz xbt@192.168.0.252:~/;`
     ssh: `sudo rm -rf /var/www/advisor && sudo mkdir -p /var/www/advisor && sudo tar -xzf ~/advisor-dist.tgz -C /var/www/advisor && sudo chown -R www-data:www-data /var/www/advisor`
  - **MUHIM**: prod OPcache `validate_timestamps=0` → cache'дан keyin **php8.5-fpm restart SHART**.
  - **`~/advisor-deploy.sh` NI ISHLATMANG** — u SEED qiladi (parol/ma'lumot clobber). Yuqoridagi maqsadli oqim.
  - `.git` egaligi buzilса: `sudo chown -R www-data:www-data /var/www/app`.
- **Cyrillic Edit tuzog'i**: latin+kirill aralash comment/string'lar «String to replace not found»
  beradi — Read'дан AYNAN baytlarni nusxalang yoki ASCII anchor ishlating.
- **Cloudflare**: `curl` orqali **multipart FAYL yuklash** reset bo'ladi (HTTP 000) — bu CF bot
  himoyasi, brauzer emas. Fayl E2E'ни curl bilan sinab bo'lmaydi (GET/JSON ishlaydi).
- **PHP 8.4 majburiy**: default php 8.3 emas — `C:\php84\php.exe` ishlat.
- **Test izolyatsiyasi**: advisor testlari DatabaseTransactions (RefreshDatabase EMAS) dev-baza ustида —
  regressiya testlari **2099 yil/davri** bilan izolyatsiya; assertlar `>=` (shared seed sizadi).
- **Bash tuzoqlari**: `$UID` readonly; JSON ичидаги apostrof quoting'ни buzadi (faylга yozing);
  login `throttle:5,1` → ko'p urinish 429.

---

## 8. SIZNING VAZIFANGIZ (yangi tabда to'ldiring)

> Bu qismni yangi tabда aniq yozing. Masalan (foydalanuvchi so'rovi asosida):
> - «Хавфсизлик» yo'nalishi uchun alohida svod turi/kategoriyasi qo'shish; yoki
> - Monitoring modulini alohida bo'lim/tab qilib ajratish; yoki
> - Yangi ustun turlari / statistika / eksport ko'rinishi; yoki
> - ... (aniq talab).

Boshlashдan oldin: `MonitoringService.php` + `MonitoringDetail.vue` ни o'qing, so'ng
tegishli test yozing, localда tekshiring, va FAQAT buyruq bilan deploy qiling.
