# XNP TZ v2.0 ↔ joriy `qurilish` moduli — farq tahlili

**Sana:** 2026-08-13
**Manba:** `C:\Users\PC\Desktop\Prokuratura loyiha`
— `XNP_TZ_v2.0_Bosqichli_Ish_Jarayoni.pdf` (45 bet), `XNP_Texnik_Topshiriq_v1.0.docx`,
10 ta ADR, `docs/fields/bosqich-{1..6}.yaml`, `docs/PROJECT_ANALYSIS.md`

---

## 1. Oldingi loyiha nima edi

**XNP — Xorazm Nazorat Platformasi**, prokuratura uchun. Laravel 12 + Filament 3 +
Inertia/Vue, PostgreSQL + PostGIS. 13 faza bajarilgan, 37 test.

Bizning `qurilish` modulimizdan **tamoyilan farqi**: XNP — *moderatsiyalangan ish
jarayoni* platformasi. Tashkilot ma'lumot **kiritadi**, prokuratura **tasdiqlaydi**,
va **faqat tasdiqlangan** ma'lumot ommaga chiqadi (ADR 0007). Bizning modul esa
hozircha *reyestr + jamlanma* — kiritilgan ma'lumot darhol jamlanmaga tushadi.

---

## 2. Asosiy tushuncha: 6 bosqich × 7 holat

TZ v2.0 qurilishni **6 ta qat'iy ketma-ket bosqich** deb belgilaydi va har bosqich
**7 holatli** tasdiqlash siklidan o'tadi:

```
kutilmoqda → ochilgan → qoralama → tasdiqlash_kutilmoqda → korib_chiqilmoqda → tasdiqlangan
                ↑                                                │
                └───────── qoralama ←── rad_etilgan ─────────────┘
```

Oldingi bosqich **tasdiqlanmaguncha** keyingisi ochilmaydi (strict sequential).

### Bizning 8 bosqich TZ'ning 6 tasiga qanday tushadi

| TZ v2.0 bosqichi | Bizdagi bosqich(lar) |
|---|---|
| 1. Obyekt yaratish | **YO'Q** — bizda obyekt yaratish bosqich emas |
| 2. Loyihachi tenderi | `designer_selection` |
| 3. Loyiha hujjatlari va ekspertiza | `design_estimate` + `urban_planning` + `complex_expertise` |
| 4. Pudratchi tenderi va shartnoma | `tender` + `contract` |
| 5. Kundalik bajarilish | `execution` |
| 6. Foydalanishga qabul | `handover` |

**Xulosa:** bizning 8 bosqich — TZ'ning 6 tasining **maydaroq** ko'rinishi va u
manbadagi Excel ustunlaridan kelib chiqqan (haqiqiy ma'lumot). Ularni tashlash
ma'lumot yo'qotish bo'lardi. To'g'ri yechim — 8 bosqichni saqlab, ularning ustiga
**TZ'ning rasmiy 6 bosqichini guruh sifatida** qo'yish: UI rasmiy timeline'ni
ko'rsatadi, ma'lumot esa mayda qoladi.

---

## 3. Farqlar jadvali

| # | TZ v2.0 talabi | Bizda | Baho |
|---|---|---|---|
| 1 | 6 bosqich (rasmiy guruh) | 8 mayda bosqich, guruhsiz | 🟡 xaritalash kerak |
| 2 | **7 holatli moderatsiya sikli** | 5 holat, moderatsiya YO'Q | 🔴 **yadro yetishmaydi** |
| 3 | **«Yuborish → ko'rib chiqish → tasdiq/rad»** | yo'q | 🔴 |
| 4 | Rad etish sababi + qoralamaga qaytish | yo'q | 🔴 |
| 5 | **Public faqat tasdiqlanganini ko'radi** | hamma narsa darhol ko'rinadi | 🔴 |
| 6 | Har bosqich uchun **maydonlar sxemasi** (JSONB) | bosqichda faqat holat+sana+izoh | 🔴 |
| 7 | **Kundalik hisobot + haftalik batch tasdiq** (5-bosqich) | oylik reja/amalda | 🔴 |
| 8 | Foto-fakt (haftada ≥5), to'lov hujjati | umumiy hujjat yuklash bor | 🟡 |
| 9 | **9 rol** | 5 rol | 🟡 4+ rol yetishmaydi |
| 10 | **Ikki yo'nalish**: qurilish / ijtimoiy ta'mirtalab | yo'q (ta'mirtalab reyestri bor, oqim yo'q) | 🟡 |
| 11 | Bosqich × rol **ruxsat matritsasi** | rol × amal (bosqichsiz) | 🟡 |
| 12 | Tasdiqlash navbati UI («Tasdiqlash» bo'limi) | yo'q | 🔴 |
| 13 | Global audit sahifasi (filtr, eksport) | obyekt ichida bor | 🟡 |
| 14 | Eskalatsiya bildirgilari (5/14/30 kun) | yo'q | 🟡 |
| 15 | Obyekt zanjiri UUID (yildan-yilga) | `is_carryover` bayrog'i | 🟡 |
| 16 | MFY darajasi, manzil, geo, quvvat, qurilish turi | `mahalla_id` bor-u ishlatilmaydi | 🟡 |
| 17 | Hujjat talabnomasi (bosqichga majburiy hujjat) | yo'q | 🟡 |
| 18 | Telegram bot, Reverb realtime | yo'q | ⚪ keyingi bosqich |

**Yetishmayotgan yadro (🔴):** moderatsiya sikli, bosqich maydonlari, kundalik/haftalik
hisobot, tasdiqlash navbati, public filtri.

---

## 4. Nima uchun moderatsiya sikli — eng muhimi

Bizning modul hozir «kim yozsa, o'sha ko'rinadi» tamoyilida ishlaydi. Prokuratura
platformasi uchun bu **yaramaydi**:

- Tasdiqlanmagan foiz dashboardga chiqib, rahbariyatni chalg'itadi.
- «Kim, qachon, nimani tasdiqladi» degan savolga javob yo'q — nazorat organi uchun
  bu asosiy talab.
- Buyurtmachi o'z ma'lumotini o'zi «yopa» oladi — nazorat ma'nosi yo'qoladi.

Shu sabab 1-navbatda **moderatsiya sikli** quriladi.

---

## 5. Amalga oshirish rejasi

| Faza | Mazmun | Nima ochadi |
|---|---|---|
| **G1** | 7 holatli moderatsiya + rad sababi + TZ 6-bosqich guruhi | Tasdiqlash zanjiri ishlaydi |
| **G2** | Bosqich maydonlari sxemasi (JSONB `malumot`) + `docs/fields` dan | Har bosqichda haqiqiy ma'lumot |
| **G3** | Tasdiqlash navbati UI + rol matritsasi (bosqich × rol) | Moderator ish o'rni |
| **G4** | Kundalik hisobot + haftalik batch tasdiq (5-bosqich) | Ijro nazorati |
| **G5** | Ikki yo'nalish + 9 rol + ijtimoiy taklif oqimi | To'liq TZ qamrovi |
| **G6** | Eskalatsiya, global audit sahifasi, hujjat talabnomasi | Nazorat quroli to'liq |

Har faza mustaqil sinaladi va yashil holatda tugaydi.

---

## 6. Saqlanadigan qarorlar

Joriy modulning quyidagi qarorlari TZ bilan **zid emas** va saqlanadi:

- 8 mayda bosqich (TZ 6 tasiga guruhlanadi) — manbadagi Excel bilan mos
- SOATO derivatsiyasi (`Объект ID` [4:9]) — TZ'da yo'q, lekin foydali
- «Tender iqtisodi faqat arzon tushganda» — TZ'da ham shu mantiq
- Kirill alifbo — TZ ham kirill
- Kartochkali dizayn — foydalanuvchi tasdiqlagan

---

## G1 BAJARILDI — 2026-08-13

### Amalga oshirilgani

**Ma'lumotlar bazasi.** `2026_08_13_160000_add_moderation_to_qurilish_stages`
migratsiyasi `object_stages` ga `tz_stage`, `submitted_at/by`, `reviewed_at/by`,
`rejection_reason`, `malumot` (jsonb) ustunlarini qo'shdi va mavjud 4888 qatorni
eski 5 holatdan TZ ning 8 holatiga ko'chirdi.

**Holat mashinasi.** `StageService` endi to'liq moderatsiya siklini boshqaradi:

    kutilmoqda -> ochilgan -> qoralama -> tasdiqlash_kutilmoqda ->
    korib_chiqilmoqda -> tasdiqlangan;  rad_etilgan -> qoralama

Ruxsat etilmagan o'tish 422 bilan rad etiladi. Bosqich `tasdiqlangan` yoki
`talab_etilmaydi` bo'lgandagina keyingisi ochiladi.

**Vakolatlar.** Yangi ruxsat `qurilish.stage.moderate` — prokuratura va admin.
Buyurtmachi `qurilish.stage.update` bilan faqat to'ldiradi va yuboradi;
o'z bosqichini o'zi yopa olmaydi (test bilan qulflangan).

**API.** Har amal alohida marshrut: `submit`, `review`, `approve`, `reject`,
`reopen`, `not-required` + `GET /qurilish/moderation/queue` (tasdiq navbati).
`/stages` javobi har bosqich uchun `actions[]` qaytaradi — SPA tugmalarni
shundan chizadi, ruxsat mantig'i mijozda takrorlanmaydi.

**SPA.** Obyekt kartochkasidagi bosqichlar TZ ning rasmiy 6 bosqichi bo'yicha
guruhlangan; har holat o'z rangi va «ish kimda» izohi bilan; rad etish sababi
buyurtmachiga ko'rinadi. Yangi sahifa: «Тасдиқлаш навбати» (eng uzoq kutgani
birinchi, 3 kundan ortig'i qizil), navigatsiyada jonli nishon soni.

**Qo'shimcha.** `qurilish:make-user` buyrug'i (rol + tashkilot doirasi bitta
amalda), `lang/oz/validation.php` — validatsiya xabarlari ham kirillda
(lokal faqat qurilish marshrutlarida yoqiladi).

### Yo'l-yo'lakay topilgan va tuzatilgan xatolar

1. `ConstructionObject::stages()` relationida ORDER BY yo'q edi — bosqichlar
   ma'lumot yangilangandan keyin aralash tartibda kelardi. Tartib endi
   relationning o'ziga biriktirilgan (`array_position`).
2. `objects` store'ida `load()` `stages` ni kartochka ma'lumoti bilan yuvib
   yuborardi va amal tugmalari yo'qolardi. Yangilash tartibi tuzatildi.
3. SPA da qolgan aralash lotin-kirill matnlar (menyu, xabarlar, mock nomlari).

### Holat taqsimoti (import qaytadan yurgizilgandan keyin)

    tasdiqlangan     2651
    kutilmoqda       1184
    talab_etilmaydi   517
    qoralama          376
    ochilgan          160
                     ----
                     4888

Bu raqamlar migratsiya (SQL) va `StageMapper` (PHP) — ikki mustaqil yo'l —
bir xil natija berganini tasdiqlaydi.

### Testlar

354 backend testi, 18 frontend testi — hammasi yashil. Yangi qamrov: to'liq
sikl (qoralama -> yuborish -> ko'rish -> tasdiq), buyurtmachi o'z bosqichini
tasdiqlay olmasligi, sababsiz rad etib bo'lmasligi, rad etilgan bosqich
keyingisini ochmasligi, navbatning rol bo'yicha cheklanishi.
