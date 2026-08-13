# Qurilish F2 — ETL: implementatsiya rejasi

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** `2026_БАРЧА_ДАСТУР_12.08.26.xlsx` dan 611 obyekt + 4 888 bosqich + oylik grafikni `qurilish` schema'ga idempotent yuklash.

**Architecture:** Python (openpyxl) faqat **xom** ma'lumotni CSV'ga chiqaradi — hech qanday biznes qoidasi yo'q. Barcha normalizatsiya (SOATO, tashkilot aliaslari, muddat, soha, bosqich bayroqlari) PHP'da alohida `Support` sinflarida, ya'ni **test bilan qoplangan**. Import komandasi `external_id` (yoki sun'iy kalit) bo'yicha upsert qiladi.

**Tech Stack:** Python 3.12 + openpyxl 3.1.5, PHP 8.4, Laravel 11.

## Global Constraints

- PHP: **`C:\php84\php.exe`**. Python: `python` (3.12.8), `PYTHONIOENCODING=utf-8`.
- Manba: `D:/kadr/2026_БАРЧА_ДАСТУР_12.08.26.xlsx` (forward slash — Windows yo'l).
- Xom `.xlsx`/`.csv` → `.gitignore` (PII yo'q, lekin repoga tushmasin).
- `RefreshDatabase` TAQIQLANGAN — `DatabaseTransactions` bilan.
- Barcha normalizatsiya PHP'da (testlanadigan), Python'da EMAS.
- Har task oxirida commit. **Deploy YO'Q.**

## Fayl tuzilishi

| Fayl | Mas'uliyati |
|---|---|
| `scripts/qurilish/extract_xlsx.py` | xlsx → 2 xom CSV (obyekt + oylik) |
| `app/Domains/Qurilish/Support/Translit.php` | kirill → lotin |
| `app/Domains/Qurilish/Support/SoatoResolver.php` | `external_id` → `master.districts.id` |
| `app/Domains/Qurilish/Support/DeadlineParser.php` | 4 format → sana/yil |
| `app/Domains/Qurilish/Support/OrgRegistry.php` | alias normalizatsiya + tashkilot yaratish |
| `app/Domains/Qurilish/Support/SectorClassifier.php` | ustun/guruh/nom → soha kodi |
| `app/Domains/Qurilish/Support/WorkTypeClassifier.php` | guruh/nom → ish turi |
| `app/Domains/Qurilish/Support/StageMapper.php` | 40 bayroq → 8 (bosqich, holat) |
| `app/Domains/Qurilish/Services/ObjectImporter.php` | bitta CSV qatorini obyektga aylantiradi |
| `app/Domains/Qurilish/Console/Commands/ImportQurilishCommand.php` | `qurilish:import` |
| `routes/console.php` (modify) | komandani ro'yxatga olish |
| `tests/Feature/Qurilish/QurilishNormalizerTest.php` | 6 support sinf birlik testlari |
| `tests/Feature/Qurilish/QurilishImportTest.php` | import + idempotentlik |

---

### Task 1: Python ekstraktor

**Files:** Create `scripts/qurilish/extract_xlsx.py`

**Interfaces:**
- Produces: `storage/app/import/qurilish/objects.csv` va `monthly.csv`
- `objects.csv` ustunlari (aynan shu tartibda):
  `sheet, row, program_code, seq, external_id, c_value, designer_raw, customer_raw, name, deadline_raw, limit_amount, carryover, new_start, f_L, f_M, f_N, f_O, f_P, f_Q, f_R, f_S, f_T, f_U, f_V, f_W, f_X, f_Y, f_Z, f_AA, f_AB, f_AC, f_AD, f_AE, f_AF, tender_amount, f_AH, contract_count, contract_amount, disbursed, disbursed_pct, financed, financed_pct, handover_plan, handover_actual, handover_left, overdue_flag, contractor_raw, note, group_label, work_type_label`
- `monthly.csv` ustunlari: `external_key, month, planned_amount`
- `external_key` = `external_id` bo'lsa o'sha; bo'lmasa `{program_code}:{row}`

- [ ] **Step 1: Ekstraktorni yozish** (kod Task ichida, quyida)
- [ ] **Step 2: Ishga tushirish** — `python scripts/qurilish/extract_xlsx.py`
- [ ] **Step 3: Tekshirish** — `objects.csv` 612 qator (sarlavha + 611)
- [ ] **Step 4: `.gitignore` ga `storage/app/import/` qo'shish**
- [ ] **Step 5: Commit**

---

### Task 2: Normalizatsiya support sinflari

**Files:** Create 6 ta `Support/*.php`

**Interfaces:**
- `Translit::toLatin(?string): ?string`
- `SoatoResolver::districtIdFor(?string $externalId, ?string $fallbackName): ?string`
- `DeadlineParser::parse(?string $raw): array{date: ?string, year: ?int}`
- `OrgRegistry::resolve(?string $raw, string $roleFlag): ?string` — tashkilot id
- `SectorClassifier::classify(?string $cValue, ?string $groupLabel, string $name): string` — soha kodi
- `WorkTypeClassifier::classify(?string $workTypeLabel, string $name): ?string`
- `StageMapper::map(array $flags): array<string, array{status: string}>`

- [ ] **Step 1: Testni yozish** (`QurilishNormalizerTest`)
- [ ] **Step 2: Testni yurgizish → FAIL**
- [ ] **Step 3: 6 sinfni yozish**
- [ ] **Step 4: Test → PASS**
- [ ] **Step 5: Commit**

---

### Task 3: `ObjectImporter` servisi + `qurilish:import` komandasi

**Files:** Create `Services/ObjectImporter.php`, `Console/Commands/ImportQurilishCommand.php`; modify `routes/console.php`

**Interfaces:**
- Consumes: barcha Task 2 sinflari
- Produces: `php artisan qurilish:import --dir=storage/app/import/qurilish [--fresh]`
- Chiqish: import hisoboti (obyekt/bosqich/oylik/tashkilot soni + ogohlantirishlar)

- [ ] **Step 1: Import testini yozish** (`QurilishImportTest`)
- [ ] **Step 2: Test → FAIL**
- [ ] **Step 3: Servis + komandani yozish**
- [ ] **Step 4: Test → PASS**
- [ ] **Step 5: Haqiqiy import** — 611 obyekt yuklanadi
- [ ] **Step 6: Jamlanmani manba bilan solishtirish** — 611 / 4 442 665,66
- [ ] **Step 7: Idempotentlik** — qayta import → bir xil sanoq
- [ ] **Step 8: To'liq regressiya + commit**

---

## Tugash mezoni

- `qurilish.objects` = 611, `SUM(limit_amount)` = 4 442 665,663
- `qurilish.object_stages` = 4 888 (611 × 8)
- Tuman aniqlangan ≥ 605/611
- Ikki marta import → bir xil sanoq (idempotent)
- `php artisan test` — barcha testlar yashil
