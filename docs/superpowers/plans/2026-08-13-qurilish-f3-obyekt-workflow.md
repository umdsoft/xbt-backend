# Qurilish F3 — Obyekt CRUD + bosqich workflow + hujjatlar: reja

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans.

**Goal:** Obyekt reyestrini yuritish (ro'yxat/filtr/yaratish/tahrirlash), 8 bosqichli holat mashinasini qoidalar bilan majburlash, hujjatlarni versiyalab saqlash — barchasi RBAC va audit jurnali ostida.

**Architecture:** Kontroller yupqa, mantiq servisda. `QurilishScope` har so'rovga avtomatik qo'llanadi (IDOR). Har o'zgarish `object_audit_log` ga tushadi. Hujjatlar `local` diskda (`storage/app/private/qurilish/objects/{id}/`) — advisor `ProjectService` naqshi.

**Tech Stack:** PHP 8.4, Laravel 11.

## Global Constraints

- PHP: `C:\php84\php.exe`. `RefreshDatabase` TAQIQLANGAN.
- Testlar UMUMIY dev bazasida — har assertion o'z fiksturasiga cheklansin.
- Yozish ruxsati: `qurilish_hokimlik`/`qurilish_prokuratura` — HECH QACHON (403).
- `lifecycle='qoralama'` obyektda bosqich o'zgartirish taqiqlanadi (422).
- Yangi route qo'shilgani uchun `php artisan route:clear` (prodda `optimize`).
- **Deploy YO'Q.**

## Fayl tuzilishi

| Fayl | Mas'uliyati |
|---|---|
| `Services/ObjectService.php` | ro'yxat/filtr/yaratish/tahrirlash + audit |
| `Services/StageService.php` | holat mashinasi qoidalari |
| `Services/DocumentService.php` | fayl yuklash/o'chirish/versiya |
| `Support/ObjectFilters.php` | so'rov parametrlari → Eloquent shartlar |
| `Http/Controllers/Api/ObjectController.php` | index/show/store/update |
| `Http/Controllers/Api/StageController.php` | index/update |
| `Http/Controllers/Api/DocumentController.php` | index/store/download/destroy |
| `Http/Controllers/Api/MonthlyController.php` | index/upsert |
| `Http/Controllers/Api/AuditController.php` | obyekt jurnali |
| `routes/api/qurilish.php` (modify) | 12 yangi route |
| `tests/Feature/Qurilish/QurilishObjectTest.php` | CRUD + scope + filtr |
| `tests/Feature/Qurilish/QurilishStageTest.php` | workflow qoidalari |
| `tests/Feature/Qurilish/QurilishDocumentTest.php` | yuklash/RBAC/versiya |

## Bosqich o'tish qoidalari (majburlanadi)

1. `jarayonda` yoki `yakunlangan` qo'yish uchun **barcha oldingi bosqichlar** `yakunlangan` yoki `talab_etilmaydi` bo'lishi shart → aks holda **422**.
2. `talab_etilmaydi` — faqat `complex_expertise` uchun (yagona shartli bosqich) → aks holda **422**.
3. `etiroz_bilan_qaytarilgan` — faqat `complex_expertise` uchun → aks holda **422**.
4. `lifecycle='qoralama'` obyektda har qanday bosqich o'zgarishi → **422**.
5. Noma'lum `stage_code` → **404**; noma'lum `status` → **422**.
6. Har o'tish `object_audit_log` ga `action='stage_change'` bilan yoziladi.
7. O'zgarishdan keyin `objects.current_stage` va `lifecycle` qayta hisoblanadi.

## Tugash mezoni

- 3 ta test fayli yashil; to'liq `php artisan test` yashil.
- Viewer rollar hech qanday yozish operatsiyasini bajara olmaydi (403).
- Begona tashkilot obyektiga murojaat → 404 (mavjudligini oshkor qilmaydi).
- Bosqichni sakrab o'tish → 422.
- Hujjat: noto'g'ri kengaytma/hajm → 422; ruxsatsiz yuklab olish → 404/403.
