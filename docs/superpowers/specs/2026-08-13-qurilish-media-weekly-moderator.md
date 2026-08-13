# Bosqich medialari, haftalik ijro arxivi va tizim moderatori

**Sana:** 2026-08-13
**Holat:** BAJARILDI
**Oldingi hujjat:** `2026-08-13-xnp-tz-v2-farq-tahlili.md` (G1 — moderatsiya sikli)

Foydalanuvchi topshirig'i (5 band):

1. Loyiha ichidagi UI/UX ni qaytadan professional yozish.
2. Har bosqich va joriy bosqich uchun surat/video; qurilish jarayonida
   haftalik ma'lumot kiritish va uning arxivi.
3. Sidebar menyularini ikonkalar bilan professional qilish.
4. Qolgan rollarning ish o'rinlarini to'liq qilish.
5. Tizim moderatori roli: login/parol berish, dastur yaratish, soha CRUD.

---

## 1. Tizim moderatori (`qurilish_moderator`)

### Nega admin'dan ajratildi

`qurilish_admin` da `*` ruxsat bor — u hamma narsani qila oladi. Agar hisob
ochuvchi odam ayni paytda qurilish bosqichini tasdiqlay olsa, u o'ziga
buyurtmachi hisobi ochib, o'zi bosqich yuborib, o'zi tasdiqlay olardi.
Nazorat platformasida bu qabul qilinmaydi.

Shuning uchun yangi rol **tor** vakolatga ega:

| Ruxsat | Moderator | Admin |
|---|---|---|
| `qurilish.view` / `export` | ha | ha |
| `qurilish.user.manage` | ha | ha |
| `qurilish.reference.manage` | ha | ha |
| `qurilish.stage.moderate` | **yo'q** | ha |
| `qurilish.object.update` | **yo'q** | ha |

Test bilan qulflangan: `test_moderator_cannot_moderate_stages_or_edit_objects`.

### Hisob boshqaruvi

`UserAdminService` ikki manbaga yozadi — `auth.user_system_access` (kirish)
va `qurilish.profiles` (tashkilot doirasi). Ular turli ulanishda, shuning
uchun bitta tranzaksiyaga o'ralmaydi; TARTIB himoya vazifasini bajaradi:
profil avval, kirish huquqi keyin. Teskarisida doirasiz-u kira oladigan
hisob qolib ketardi.

Qat'iy qoidalar:

- **Buyurtmachi va boshqarma tashkilotsiz yaratilmaydi.** Ularsiz
  `QurilishScope` fail-closed shoxiga tushadi va foydalanuvchi hech nima
  ko'rmaydi — bu qo'lda SQL yozganda eng ko'p qilinadigan xato.
- **Hisob o'chirilmaydi, bloklanadi.** O'chirilgan hisob o'zi qoldirgan
  audit izlarini «kim» siz qoldirardi.
- **O'z hisobini o'zi bloklab bo'lmaydi** — tizim administratorsiz qolmasin.
- **Parol jurnalga tushmaydi** (`AdminAuditLogger` uni `REDACTED` qiladi) va
  faqat yaratish/tiklash javobida, bir marta qaytadi.

### Spravochniklar

Dastur, soha va tashkilot CRUD. Asosiy qoida ekranda ko'rinadi: har qatorda
«nechta obyektda ishlatilmoqda» ustuni bor va **ishlatilayotgan yozuv
o'chirilmaydi — nofaol qilinadi**. Aks holda unga bog'langan yuzlab obyekt
«dasturisiz» qolib, jamlanmalar jim buzilardi.

Kod (`code`) faqat `[a-z0-9_]` — u import mosligining kaliti; kirillcha kod
kiritilsa keyingi import mos kelmay qolardi.

---

## 2. Bosqich medialari

### Nega hujjatdan alohida jadval

`object_documents` — huquqiy hujjat: versiyalanadi («shartnomaning
3-tahriri»), `sha256` bo'yicha takrorlanmaydi. Surat/video esa **dalil**:
bir bosqichda o'nlab bo'ladi, versiyasi yo'q, lekin uning **olingan
sanasi** hal qiluvchi. Ikkalasini bitta jadvalga tiqish har ikkalasini
ham buzardi.

`object_media` maydonlari: `stage_code`, `weekly_report_id`, `kind`
(photo/video), `taken_at`, `is_cover`, o'lchamlar, `sha256`.

Qoidalar:

- **Kelajakdagi sana rad etiladi** — u dalilni soxtalashtiradi.
- **Sana ko'rsatilmasa NULL qoladi**, «bugun» qo'yilmaydi: taxmin qilingan
  sana yolg'on, bo'sh sana halol. Galereyada bunday tasvirlar oxirida,
  alohida guruhda turadi.
- **Bir bosqichda bitta asosiy surat** (`is_cover`) — u obyekt
  kartochkasidagi bosqich qatorida eskiz bo'lib ko'rinadi.
- Video **qayta kodlanmaydi**: transkoder yo'q, shuning uchun chegara
  qattiq (200 MB) va faqat brauzer o'zi o'ynatadigan formatlar qabul
  qilinadi. Fayl `Accept-Ranges` bilan beriladi — usiz brauzer butun
  faylni yuklab bo'lmaguncha o'ynatmaydi.

---

## 3. Haftalik ijro hisoboti va arxiv

### Nega hafta

Ijro bosqichi oylab davom etadi. Oylik grafik faqat **pul** ko'rsatadi;
nazorat organiga esa qurilishning o'zi kerak: shu haftada nima qilindi,
nechta ishchi chiqdi, nima to'sqinlik qildi, dalil surati bormi. Oy
oxirida bularni eslab bo'lmaydi.

`object_weekly_reports` — kalit `(obyekt, yil, hafta)`, yagona: bir haftaga
ikkinchi hisobot ochilmaydi, aks holda arxivda qaysi biri haqiqiy ekani
noaniq bo'lardi.

Muhim qarorlar:

- **Haftalik o'sish HOSILA.** Foydalanuvchi faqat umumiy bajarilish
  foizini kiritadi; `week_progress_pct` oldingi hisobotdan farq sifatida
  hisoblanadi. Ikkalasini ham so'rasak, ular bir-biriga zid bo'lib qolardi.
- **Hisobot ham moderatsiyadan o'tadi** — bosqich bilan bir xil holat
  lug'ati. Tasdiqlanmagan raqam rasmiy emas, lekin arxivda qolaveradi.
- **Bo'sh hisobot yuborilmaydi** (`works_done` majburiy) — u moderator
  vaqtini yeydi.
- **Kiritilmagan haftalar ko'rsatiladi.** Ijro boshlangan haftadan
  bugungacha har hafta hisobot talab qiladi; yo'qlari sahifada ro'yxatdan
  OLDIN turadi. Bo'sh hafta — bu ham ma'lumot, hatto eng muhimi.

---

## 4. UI/UX

### Ikonka tizimi

`AppIcon.vue` — 60 ta o'z ikonkamiz. Tayyor kutubxona (lucide, heroicons)
olinmadi: u ~300 KB olib keladi va uslubi platformaning «chizma varag'i»
tiliga begona. Bizniki bir qoidada: 24×24 to'r, 1.6 px chiziq, faqat
kontur, `currentColor` — ya'ni ular chizmadagi shartli belgilar kabi
ko'rinadi.

### Navigatsiya

Sidebar uch bo'limga bo'lindi, chunki bu yerda uch xil ish bor va ular
aralashsa menyu «hamma narsa ro'yxati» ga aylanadi:

- **Назорат** — ko'rish va jamlash (hamma uchun)
- **Ижро** — kundalik ish (buyurtmachi, prokuratura)
- **Бошқарув** — tizimning o'zi (moderator)

Har band **ruxsat bo'yicha** ko'rinadi: bosilganda 403 beradigan menyu
bandi yomon interfeys. Yig'iladigan rejim (`localStorage` da saqlanadi),
tor ekranda qatlam menyu, javob kutayotgan ish soni nishonda.

### Umumiy komponentlar

`PageHeader` (butun ilovada bitta sarlavha shakli), `AppModal`,
`EmptyState`, `NoticeBar`, `MediaGallery`, `RoleWorkStrip`. Tugmalar uch
darajaga bo'lindi (birlamchi / kontur / jim) + tasdiq va rad etish uchun
alohida vazn — ular qaytarib bo'lmaydigan amallar.

### Rol bo'yicha bosh sahifa

Jamlanma paneli **viloyat** haqida gapiradi, foydalanuvchi esa o'zining
navbatdagi ishini qidiradi. Shuning uchun panelning birinchi bloki —
«Сизнинг ишингиз»: rolga qarab nechta bosqich va nechta haftalik hisobot
javob kutayotgani, boshqarma uchun reyestr va obyekt kiritish.

Blok faqat ish bo'lganda ko'rinadi: bo'sh «sizda ish yo'q» kartasi ekranni
egallaydi-yu, hech nima demaydi.

### Boshqarma uchun obyekt formasi

Avval `POST /objects` endpointi bor edi, lekin UI umuman yo'q edi — ya'ni
boshqarmaning ASOSIY vazifasi (birinchi maqsad: «har boshqarma o'z
obyektlarini kiritadi») bajarib bo'lmasdi. Forma faqat boshqarma
to'ldiradigan maydonlarni so'raydi; moliya va pudratchi keyin, buyurtmachi
tomonidan kiritiladi.

---

## 5. Yo'l-yo'lakay tuzatilgan xatolar

1. **Media URL ikki marta `/api` olardi.** SPA `fileUrl()` prefiksni o'zi
   qo'shadi, backend ham berardi — `/api/api/...` chiqib, surat va video
   yuklanmasdan qolardi. Xato jim edi: ro'yxat to'g'ri kelardi, faqat
   tasvir o'rnida bo'sh katak turardi. Test URL shaklini qulfladi.
2. **Dashboard sarlavhasida rol qotirilgan edi** (`prokuratura ? ... :
   'Ҳокимлиги'`) — boshqarma o'z panelini hokimlikniki deb o'qirdi.
3. **Validatsiya xabarlari inglizcha edi.** `lang/oz/validation.php`
   qo'shildi va lokal faqat qurilish marshrutlarida yoqiladi
   (`EnsureQurilish`), boshqa domenlarga tegmasin.

---

## 6. Testlar

379 backend testi, 18 frontend testi — hammasi yashil.

Yangi qamrov (25 test):

- Moderator bosqichni tasdiqlay olmasligi va obyektni tahrirlay olmasligi
- Parol jurnalga tushmasligi
- Buyurtmachi/boshqarma tashkilotsiz yaratilmasligi
- O'z hisobini o'zi bloklay olmasligi; bloklangan hisob domenga kirmasligi
- Ishlatilayotgan soha o'chirilmay, nofaol qilinishi
- Haftalik hisobot faqat ijro bosqichida yuritilishi
- Bir haftaga bitta hisobot; haftalik o'sish hosila ekani
- Bo'sh hisobot yuborilmasligi; tasdiqqa yuborilgani tahrirlanmasligi
- Kelajakdagi surat sanasi rad etilishi
- Bir bosqichda bitta asosiy surat
- O'zga tashkilot medialariga yetib bo'lmasligi (IDOR)

---

## 7. Dev hisoblari (lokal)

| Login | Rol | Parol |
|---|---|---|
| `qurilish_dev` | hokimlik | (eski) |
| `qurilish_prok` | prokuratura | `Qurilish_2026` |
| `qurilish_buyurtmachi_yol` | buyurtmachi | `Qurilish_2026` |
| `qurilish_mod` | tizim moderatori | `Qurilish_2026` |
| `qurilish_bosh` | boshqarma | `Qurilish_2026` |

Prodda parollar `qurilish:make-user` buyrug'i yoki moderator ekrani orqali
generatsiya qilinadi va bir marta ko'rsatiladi.
