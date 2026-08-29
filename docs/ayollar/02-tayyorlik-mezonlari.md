# «Ayollar Balansi» — tayyorlik mezonlari (promt §13)

Har band uchun: holat va DALIL. «Bajarildi» deyish uchun tekshiriladigan
narsa bo'lishi kerak.

Sana: 2026-08-29 · Backend 665 test · Frontend 69 test · Qobiq 4 test.

| # | Mezon | Holat | Dalil |
|---|---|---|---|
| 1 | `CategoryResolver` 23 qadamning har biri uchun testdan o'tadi | ✅ | `tests/Unit/Ayollar/CategoryResolverTest.php` — 22 zinapoya qadami `ladderProvider()` da + 23-qadam `incomplete`. 55 test |
| 2 | Har MFY uchun `yashil + sariq === jami` | ✅ | `BalanceCalculatorTest::test_green_plus_yellow_equals_total` + `BalanceCalculator::verify()` har yopishda tekshiradi |
| 3 | Har MFY uchun `qizil <= jami` | ✅ | `test_red_never_exceeds_total` — 6 belgili bitta ayol ham 1 ta sanaladi |
| 4 | Tuman balansi = MFYlar yig'indisi (og'ish 0) | ✅ | `test_district_equals_sum_of_mahallas` + `verifyRollUp()` |
| 5 | Viloyat balansi = 13 tuman yig'indisi (og'ish 0) | ✅ | `test_region_equals_sum_of_districts` |
| 6 | Anketa aviarejimda to'liq to'ldiriladi va tarmoq qaytganda yuboriladi | ⚠️ **qisman** | Kod tayyor (IndexedDB + navbat + avtomatik yuborish), lekin HAQIQIY planshetda aviarejimda sinalmagan |
| 7 | Ilova o'rtada o'chirilsa — javoblar yo'qolmaydi | ✅ | Har javob 400 ms debounce bilan IndexedDB'ga; `AnketaStore::persist()` tarmoqqa bog'liq emas |
| 8 | JShShIR dublikati saqlashda ushlanadi | ✅ | `AnketaValidationTest::test_duplicate_pinfl_is_detected_across_districts` + `women_pinfl_hash_unique` partial indeks |
| 9 | Maskalangan maydonni ochish jurnalga yoziladi | ✅ | `AyollarApiTest::test_reveal_pii_is_logged` |
| 10 | QR sahifasi autentifikatsiyasiz ochiladi va PII ko'rsatmaydi | ✅ | `test_public_qr_page_has_no_personal_data` — javob tanasida ism ham, JShShIR ham yo'q |
| 11 | Qizil ro'yxatdagi ismlar faqat 3 rolga ko'rinadi | ✅ | `test_red_list_restricted_to_three_roles` — 3 ta ruxsat, 4 ta rad |
| 12 | Rozilik imzosisiz anketa saqlanmaydi | ✅ | `test_consent_is_mandatory` (backend) + `NewAnketaPage` tugmani bloklaydi |
| 13 | Excel eksport rasmiy shakl bilan piksel darajasida mos | ❌ **bajarilmagan** | Rasmiy shakl fayli berilmagan. Eksport `metric_registry` dan generatsiya qilinadi va tuzilma to'g'ri, lekin taqqoslash uchun etalon yo'q |
| 14 | Barcha ekranlar Figma bilan solishtirilgan | ⚠️ **qisman** | Figmada FAQAT `01 · Fondation` bor. Tokenlar, tipografika va `Balans tasmasi` 1:1; 22 ekran esa faylda yo'q va §10 talabidan qurildi |
| 15 | Lighthouse PWA bahosi 90+ | ❓ **o'lchanmagan** | PWA konfiguratsiyasi to'liq (manifest, SW, offline), lekin Lighthouse yuritilmagan |
| 16 | 3G da birinchi yuklanish < 4 s, keyingi < 1 s | ❓ **o'lchanmagan** | Build hajmi: JS 98 KB + 96 KB (Dexie) gzip'da ~69 KB; SW 75 fayl precache |

---

## Qo'shimcha bajarilganlar (promt §6 maxfiylik)

| Talab | Holat | Dalil |
|---|---|---|
| Maydon darajasida AES-256-GCM shifrlash | ✅ | `PiiCipher`; `test_pinfl_is_encrypted_at_rest` bazada xom qiymat yo'qligini tekshiradi |
| Kalit `.env` da emas | ✅ | `AYOLLAR_PII_KEY_PATH`, HKDF bilan ikki mustaqil kalit (shifrlash + qidiruv) |
| Maskalash `•••• 4127` | ✅ | `Woman::pinfl` accessor maskalangan qiymat qaytaradi; xom qiymat faqat `revealRawPii()` |
| Shifrlangan ustunlar javobga tushmaydi | ✅ | `test_encrypted_columns_never_serialize` + `test_registry_response_has_no_pii_columns` |
| Eksportda PII yo'q, suv belgisi bor | ✅ | `ExportController` — ustunlarda F.I.Sh. ham yo'q; oxirgi qatorda kim/qachon/IP |
| Planshetda avtomatik qulf | ✅ | Flutter qobiq: 30 daqiqa harakatsizlik + fonga o'tib qaytganda |
| Masofadan tozalash | ✅ | `NativeBridge.clearAllData()` -> secure storage + WebView cache/localStorage/cookie |

---

## Ochiq savollar va cheklovlar

1. **Anketa savollarining matni yo'q.** Ma'nosi aniq savollar (bandlik,
   ta'lim, migratsiya, oilaviy holat, V bo'lim) to'liq ishlaydi —
   ularning variantlari `rules.json` dan hosila. Qolganlari
   «N-savol» sifatida ko'rsatiladi. **Matn to'qib chiqarilmadi**: soxta
   savol haqiqiy deb qabul qilinishi va noto'g'ri ma'lumot yig'ilishi
   mumkin edi.

2. **Figma ekranlari faylda yo'q.** Promt 22 ekran va 46 ikonka
   va'da qiladi; `bzMRZuaaOOI8SBmLQw8wF1` da faqat `01 · Fondation`
   sahifasi mavjud. Ranglar, tipografika va `Balans tasmasi`
   `get_design_context` bilan 1:1 ko'chirildi.

3. **Yuklama testi o'tkazilmagan.** Promt §12 8-bosqichda 486 MFY ×
   2000 ayol ≈ 1 mln yozuv talab qiladi. Hozirgi namoyish ma'lumoti
   331 yozuv. Balans hisoblash INKREMENTAL (anketa saqlanganda bitta
   MFY), shuning uchun miqyos muammosi kutilmaydi — lekin bu
   o'lchanmagan.

4. **Redis yo'q.** Prodda kesh/navbat `database`/`file` drayverida.
   Promt Redis+Horizon talab qiladi; platformada ular hali
   o'rnatilmagan (mahalla moduli backlog'ida).

5. **Excel etaloni yo'q.** Rasmiy shakl fayli berilmagan, shuning
   uchun «piksel darajasida moslik» tekshirib bo'lmaydi.
