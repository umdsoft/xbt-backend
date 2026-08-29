# «Ayollar Balansi» — markaziy tizimga moslashtirish qarorlari

Sana: 2026-08-29. Manba: BUILD PROMPT «Ayollar Balansi» (Digital Xorazm).

Promt markaziy platformadan xabarsiz holda tayyorlangan. Quyida promt talabi
bilan mavjud platforma o'rtasidagi HAR BIR farq va tanlangan yechim.
Funksional talablar (domen, toifalash, maxfiylik, offline) **o'zgarishsiz**
bajariladi — moslashtirish faqat joylashuv va infratuzilma darajasida.

| # | Promt talabi | Platformada haqiqat | Qaror |
|---|---|---|---|
| 1 | «Laravel loyihasini yarat» | Yagona backend `D:\kadr\platform` (prod `/var/www/app`), 7 domen ichida | Yangi loyiha YO'Q. `app/Domains/Ayollar` domeni |
| 2 | PHP 8.3 · Laravel 11 | PHP 8.4.19 · Laravel 13.8 | Mavjud versiya |
| 3 | `regions/districts/mahallas` jadvallari | `master` schema'da bor: 1 viloyat, 13 tuman, **509** MFY | Yaratilmaydi — `master` dan o'qiladi. (Promtdagi 486 eskirgan) |
| 4 | `users(mahalla_id, district_id, ...)` | Markaziy `auth.users` (SSO, 8 tizim) | `ayollar.staff` profil jadvali: `user_id` → doira |
| 5 | spatie rollari | 6 domen `{X}Access` kod-xaritasi ishlatadi (spatie faqat HR'da) | `AyollarAccess` kod-xaritasi + promtdagi rol kodlari AYNAN. spatie permission'lari `RolePermissionSeeder`ga qo'shimcha ravishda ham qo'shiladi |
| 6 | Tailwind CSS 3 | Platformadagi 7 SPA — Tailwind v4 | v4 `@theme` bilan, tokenlar AYNAN promt §3 dan |
| 7 | `maatwebsite/excel` | O'rnatilmagan; `App\Support\SimpleXlsx` bor (dependency-siz XLSX) | `SimpleXlsx` ishlatiladi — yangi paket YO'Q |
| 8 | `simplesoftwareio/simple-qrcode` | Yo'q | `bacon/bacon-qr-code` (sof PHP, MIT) qo'shiladi |
| 9 | Redis (kesh/navbat/sessiya) | Prodda hali yo'q (P1 backlog) | `database`/`file` drayveri; Redis kelganda `.env` o'zgaradi |
| 10 | Laravel Horizon | Yo'q | Qo'shilmaydi — navbat `database` drayverida |
| 11 | `/api/auth/login`, `/api/anketas` | `/api/login` markaziy; 7 modul `/api/{modul}/...` | `/api/ayollar/...` prefiksi. Kirish — markaziy `/api/login` |
| 12 | AES-256-GCM, kalit KMS'da | KMS yo'q (LAN'dagi Ubuntu server) | `PiiCipher` servisi AES-256-GCM; kalit repodan TASHQARIDAGI faylda (`AYOLLAR_PII_KEY_PATH`, 0400). KMS kelganda faqat kalit manbai o'zgaradi |
| 13 | Figma: 5 sahifa, 22 ekran, 46 ikonka | Faylda **faqat `01 · Fondation`** bor | Tokenlar/tipografika/`Balans tasmasi` — Figmadan 1:1. Ekranlar — promt §10 funksional talabidan, shu dizayn tizimida |

## Figmadan 1:1 olingan (2026-08-29)

`bzMRZuaaOOI8SBmLQw8wF1` · `01 · Fondation` (`0:1`):

- **Ranglar** (`2:7`): Ink `#0E2430`, Turkuaz `#12808C`, Yashil `#2F7D5B`,
  Sariq `#C4881A`, Qizil `#B23A32`, Paper `#F1F3F1`, Line `#DCE2DF`
- **Tipografika** (`4:2`): Display Manrope ExtraBold 52 · H1 30 · Raqam 40 ·
  H3 Manrope Bold 17 · Body IBM Plex Sans 15 · Label IBM Plex Sans SemiBold 11 ·
  Mono IBM Plex Mono Medium 14
- **`Balans tasmasi`** (`4:26`): karta 760×244, r12, border `#DCE2DF`, p 24/22,
  gap 10; yuqori qator Manrope ExtraBold 34 / lh 1.05 / ls −1px; tasma h38 r7
  (segmentlar ichida Manrope Bold 14 oq, pl 13); qizil ost-chiziq h10 r5,
  trek `#EDF0EE`; legenda gap 26, nuqta 10px r3, Manrope Bold 15 +
  IBM Plex Sans 11.5; izoh `#F1F3F1` r8 px14 py10 IBM Plex Sans 12/1.4

Komponent tavsifi (Figmadan): «Yashil + sariq segmentlari doim 100% ni tashkil
qiladi. Qizil chiziq ularning OSTIDAN o'tadi — chunki qizil toifa alohida
bo'lak emas, yashil va sariq ichidan chiqadi.»

## Hal qilinishi kerak (foydalanuvchidan)

1. **Anketa savollari matni** — promtda faqat raqamlar bor (q7, q9, q10, q23,
   q27, q30, q31 va «istak» 16–19, 21, 26, 28). Savol MATNLARI yo'q.
   Vaqtinchalik yechim: `rules.json` da SEMANTIK kalitlar (`employment_status`,
   `education_status`, `migration_status`) va ularni savol raqamiga bog'lovchi
   `fields` xaritasi. Haqiqiy anketa kelganda faqat xarita o'zgaradi,
   toifalash zinapoyasi TEGILMAYDI.
2. **Migratsiya savolining raqami** — §1.2 16-qadamda ishlatiladi, lekin
   raqamlanmagan. `q13` deb belgilandi (III bo'lim, «istak» savollari 16–19 dan
   oldin). Tasdiqlash kerak.
3. **Figma ekranlari** — 22 ekran faylda yo'q. Qo'shilsa, `get_design_context`
   bilan qayta ko'chiriladi.
