# «Ayollar Balansi» — tayyorlik mezonlari (promt §13)

Har band uchun: holat va DALIL. «Bajarildi» deyish uchun tekshiriladigan
narsa bo'lishi kerak.

Yangilangan: 2026-08-29 · Backend **684** test · Frontend **78** · Qobiq **4**.

| # | Mezon | Holat | Dalil |
|---|---|---|---|
| 1 | `CategoryResolver` 23 qadamning har biri uchun testdan o'tadi | ✅ | `tests/Unit/Ayollar/CategoryResolverTest.php` — 22 zinapoya qadami `ladderProvider()` da + 23-qadam `incomplete`. Frontend AYNAN o'sha 22 holatni `src/__tests__/rules.spec.ts` da yuritadi |
| 2 | Har MFY uchun `yashil + sariq === jami` | ✅ | `BalanceCalculatorTest::test_green_plus_yellow_equals_total` + `verify()` har yopishda |
| 3 | Har MFY uchun `qizil <= jami` | ✅ | `test_red_never_exceeds_total` — 6 belgili bitta ayol ham 1 ta |
| 4 | Tuman = MFYlar yig'indisi (og'ish 0) | ✅ | `test_district_equals_sum_of_mahallas` + `verifyRollUp()` |
| 5 | Viloyat = 13 tuman yig'indisi (og'ish 0) | ✅ | `test_region_equals_sum_of_districts` |
| 6 | Anketa aviarejimda to'ldiriladi, tarmoq qaytganda yuboriladi | ⚠️ **qisman** | Kod to'liq (IndexedDB + navbat + avtomatik yuborish + konflikt), lekin HAQIQIY planshetda aviarejimda sinalmagan |
| 7 | Ilova o'chirilsa — javoblar yo'qolmaydi | ✅ | Har javob 400 ms debounce bilan IndexedDB'ga; saqlash tarmoqqa bog'liq emas |
| 8 | JShShIR dublikati saqlashda ushlanadi | ✅ | `test_duplicate_pinfl_is_detected_across_districts` + `women_pinfl_hash_unique` partial indeks |
| 9 | Maskalangan maydonni ochish jurnalga yoziladi | ✅ | `test_reveal_pii_is_logged` + `test_pii_reveal_is_in_both_logs` — IKKALA jurnalga |
| 10 | QR sahifasi autentifikatsiyasiz ochiladi va PII ko'rsatmaydi | ✅ | `test_public_qr_page_has_no_personal_data` |
| 11 | Qizil ro'yxatdagi ismlar faqat 3 rolga | ✅ | `test_red_list_restricted_to_three_roles` — 3 ruxsat, 4 rad |
| 12 | Rozilik imzosisiz anketa saqlanmaydi | ✅ | `test_consent_is_mandatory` + `NewAnketaPage` tugmani bloklaydi |
| 13 | Excel eksport rasmiy shakl bilan mos | ⚠️ **etalonsiz** | Shakl `metric_registry` dan generatsiya qilinadi va tuzilma to'g'ri (`test_balance_export_is_watermarked`), lekin taqqoslash uchun rasmiy fayl berilmagan |
| 14 | Barcha ekranlar Figma bilan solishtirilgan | ⚠️ **qisman** | Figmada FAQAT `01 · Fondation` bor. Undagi HAMMA narsa 1:1 ko'chirilgan va `/dizayn` sahifasida tekshirish mumkin. 22 ekran faylda yo'q |
| 15 | Lighthouse PWA bahosi 90+ | ❓ **o'lchanmagan** | PWA to'liq (manifest, SW, offline, 78 precache), lekin Lighthouse yuritilmagan |
| 16 | 3G da birinchi yuklanish < 4 s, keyingi < 1 s | ❓ **o'lchanmagan** | Bundle: 98 KB + 96 KB (Dexie) gzip'da ~69 KB |

---

## Promt §7 va §11 — endpointlar

| Talab | Holat | Izoh |
|---|---|---|
| `GET /a/{qr_token}` ochiq tekshiruv | ✅ | `/api/ayollar/public/a/{token}` + SPA `/a/:token` |
| `GET /api/export/anketa/{id}/pdf` | ✅ | QR o'ng yuqorida 25×25 mm, ostida raqam matn bilan. **V bo'lim javoblari PDF'ga tushmaydi** |
| `GET /api/export/balance/{type}/{id}` Excel | ✅ | Suv belgisi bilan |
| `GET /api/bootstrap` | ✅ | `/api/ayollar/bootstrap`, ETag bilan |
| `POST /api/anketas/batch` idempotent | ✅ | `test_batch_is_idempotent` |
| `POST /api/women/check-duplicate` 409 + joylashuv | ✅ | `test_duplicate_reports_location` |
| `POST /api/auth/login` PIN + qurilma ID | ✅ *moslashtirilgan* | Markaziy SSO + **qurilma darajasidagi PIN qulfi** (`Planshet 4`). PIN server paroli emas — u qurilmani qo'riqlaydi, PBKDF2 200k iteratsiya, 5 urinishdan keyin bekor qilinadi |

## Promt §10 — ekranlar

Veb 1–8: ✅ · Planshet 1–7: ✅ · Anketa 1–7: ✅ (yoshga moslashuvchan sxema
qisqartirilgan shaklni o'zi hosil qiladi) · **Dizayn tizimi** (`/dizayn`) —
qo'shimcha, Figma Fondation'ning tirik nusxasi.

## Promt §5 — administrator

| Talab | Holat |
|---|---|
| Rollar | ✅ 7 rol, `ayollar:make-user` buyrug'i |
| Lug'atlar | ✅ `/boshqaruv-tizimi` → Metrikalar lug'ati; `code` o'zgartirilmaydi |
| Audit | ✅ Amallar jurnali + maxfiy kirish jurnali (alohida) + tizim salomatligi |

---

## Maxfiylik (promt §6)

| Talab | Holat | Dalil |
|---|---|---|
| Maydon darajasida AES-256-GCM | ✅ | `PiiCipher`; `test_pinfl_is_encrypted_at_rest` |
| Kalit `.env` da emas | ✅ | `AYOLLAR_PII_KEY_PATH`, HKDF bilan ikki mustaqil kalit. Admin panelida ogohlantirish ko'rinadi |
| Maskalash `•••• 4127` | ✅ | `Woman::pinfl` accessor; xom qiymat faqat `revealRawPii()` |
| Shifrlangan ustunlar javobga tushmaydi | ✅ | `test_encrypted_columns_never_serialize` |
| Eksportda PII yo'q, suv belgisi bor | ✅ | `test_registry_export_has_no_pii`; eksport JURNALGA ham tushadi |
| PDF'da V bo'lim yo'q | ✅ | `test_pdf_html_omits_sensitive_questions` |
| Jurnalda anketa javoblari yo'q | ✅ | `test_audit_log_never_stores_answers` |
| Planshetda avtomatik qulf | ✅ | Biometrika (30 daq) + PIN qulfi |
| Masofadan tozalash | ✅ | `NativeBridge.clearAllData()` |

---

## Ochiq savollar va cheklovlar

1. **Anketa savollarining matni yo'q.** Ma'nosi aniq savollar (bandlik,
   ta'lim, migratsiya, oilaviy holat, V bo'lim) to'liq ishlaydi — ularning
   variantlari `rules.json` dan HOSILA (`src/lib/questions.ts`), ya'ni
   to'qilmagan. Qolganlari «N-savol» sifatida ko'rsatiladi. **Matn to'qib
   chiqarilmadi**: soxta savol haqiqiy deb qabul qilinishi mumkin edi.

2. **Migratsiya savoli `q13` deb taxmin qilingan.** Tasdiqlash kerak.
   O'zgarsa, faqat `rules.json` dagi `fields` xaritasi tahrirlanadi —
   zinapoyaga tegilmaydi.

3. **Figmada 22 ekran yo'q.** `bzMRZuaaOOI8SBmLQw8wF1` da faqat
   `01 · Fondation`. Undagi hamma narsa (7 rang, 7 tipografika uslubi,
   `Balans tasmasi`) `get_design_context` bilan 1:1 ko'chirilgan va
   `/dizayn` sahifasida solishtirish mumkin. Ekranlar qo'shilsa, ular
   qayta ko'chiriladi — dizayn tizimi tayyor turibdi.

4. **Yuklama testi o'tkazilmagan.** 486 MFY × 2000 ayol ≈ 1 mln yozuv
   talab qilinadi; hozirgi namoyish 360 yozuv. Balans INKREMENTAL
   hisoblanadi, shuning uchun miqyos muammosi kutilmaydi — lekin
   o'lchanmagan.

5. **Redis yo'q.** Kesh/navbat `database`/`file` drayverida. Platformada
   Redis hali o'rnatilmagan (mahalla moduli backlog'ida).

6. **PDF shrifti — DejaVu Sans.** Manrope/IBM Plex `woff2` formatida va
   dompdf TTF talab qiladi. Rasmiy hujjatda kirill/lotin belgilarining
   to'g'ri chiqishi shrift tanlovidan muhimroq.
