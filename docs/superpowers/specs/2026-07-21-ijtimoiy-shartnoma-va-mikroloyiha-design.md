# Ijtimoiy shartnoma va mikroloyiha tizimi — dizayn

**Sana:** 2026-07-21
**Holat:** foydalanuvchi ko'rigini kutmoqda

## 1. Nima o'zgaradi

Hozirgi tizim bitta ishni bajaradi: hovli-joy obodonlashtirilganini surat orqali kuzatib boradi. Kengaytirilgandan keyin bu **jarayonni kuzatuvchi qism** bo'lib qoladi, uning atrofida uchta yangi qatlam paydo bo'ladi:

```
                    ┌─────────────────────────────┐
                    │   VILOYAT / TUMAN RAHBARI   │
                    │        (ko'rish)            │
                    └──────────────┬──────────────┘
                                   │
        ┌──────────────────┬───────┴────────┬──────────────────┐
        │                  │                │                  │
  IJTIMOIY SHARTNOMA   MIKROLOYIHA    IJTIMOIY OBYEKT    KO'RSATKICHLAR
  (mahalla raisi)   (hokim yordamchisi)  (kadastrdan)      (import)
        │                  │                │                  │
        └──────────────────┴────────┬───────┴──────────────────┘
                                    │
                        ┌───────────▼────────────┐
                        │  OBODONLASHTIRISH      │
                        │  (mavjud — surat, AI)  │
                        │  jarayonni saqlaydi    │
                        └────────────────────────┘
```

### Shartnoma va obodonlashtirish o'rtasidagi bog'liqlik

Foydalanuvchi buni aniq belgilab berdi va bu dizaynning eng muhim qarori:

> «Sen bajarildi deb topadigan qismni tizim ham odam ham qabul qiladi (bu shartnomaga bog'liq emas). Biz shartnomani ma'lumot db da turishi uchun qilyabmiz... Obodonlashtirish jarayonni saqlab boradi.»

Ya'ni: **shartnoma hujjat, obodonlashtirish esa mustaqil o'lchov.** Ish bajarilgani surat va AI tahlili orqali aniqlanadi — shartnoma bor-yo'qligiga qaramasdan. Ular xonadon orqali bog'lanadi, sabab-oqibat bilan emas.

Bu muhim, chunki teskarisi (shartnoma bajarilganini o'zi belgilash) tizimni hisobot mashinasiga aylantiradi. Hozirgi qiymat aynan mustaqil o'lchovda.

## 2. Hal qilinishi kerak bo'lgan savol: «Жами хонадон»

Ikki manba ikki xil raqam beradi va **ikkalasi ham to'g'ri**:

| Manba | Shovot | Nimani o'lchaydi |
|---|---|---|
| `master.buildings` (kadastr) | 34 648 | jismoniy turar-joy binosi |
| Oila reestri (rasmiy) | 10 866 xonadon / 49 188 oila | ma'muriy ro'yxatdagi xo'jalik |

Farq xato emas — turli narsa sanaladi. Bir hovlida bir necha bino bo'ladi; bir xonadonda bir necha oila yashaydi.

**Taklif: ikkalasini ham saqlash, har birini o'z joyida ishlatish.**

- Obodonlashtirish maxraji → **kadastr binosi**. Sabab: siz binoni suratga olasiz, ma'muriy xo'jalikni emas. «300 binodan 45 tasi o'zgardi» — o'lchanadigan gap.
- Ijtimoiy/kambag'allik maxraji → **rasmiy oila soni**. Sabab: kambag'allik oilaga beriladi, binoga emas.

Panelda ikkalasi alohida ustun bo'ladi, sarlavhasi farqni ochib beradi. Bitta raqamga majburlash — qaysi birini tanlasak ham — ikkinchi bo'limni noto'g'ri qiladi.

## 3. Ma'lumotlar bazasi

### 3.1 Prinsip: boshqa tumanlar ham shu tartibda

Foydalanuvchi talabi: «kelajakda boshqa tuman(shahar) larni ham huddi shu tartibda ishlaymiz shunga DB professional loyihalashishi kerak».

Buning amaliy ma'nosi — **hech qayerda «Shovot» yozilmaydi**:

1. Har bir jadval `mahalla_id` yoki `district_id` orqali `master` ga bog'lanadi. Tuman filtri — so'rov sharti, kod emas.
2. Ro'yxatlar (shartnoma turi, loyiha yo'nalishi, holat) — `master` dagi ma'lumotnoma jadvallarida, `enum` yoki `match()` da emas. Yangi tur qo'shish = bitta qator, deploy emas. Bu `object_types` da allaqachon shunday qilindi va o'zini oqladi: 1 080 xil yozuvni 20 turga ajratish uchun kod tegilmadi.
3. Nom moslashtirish (`MahallaMatcher` + `mahalla_aliases`) har tumanda ishlaydi — u tuman doirasida qidiradi.

### 3.2 Yangi jadvallar (`mahalla` sxemasi)

```
social_contracts
  id, house_id -> houses, mahalla_id, contract_type_id -> master.contract_types
  contract_number, signed_at, amount, status, notes
  created_by -> users (mahalla raisi), created_at, updated_at, deleted_at
  UNIQUE (mahalla_id, contract_number)

contract_files
  id, contract_id -> social_contracts, path, original_name, mime, size_bytes
  uploaded_by -> users, created_at

micro_projects
  id, mahalla_id, category_id -> master.project_categories
  title, description, planned_amount, actual_amount
  planned_start, planned_end, actual_end, status
  object_building_id -> master.buildings   (ixtiyoriy: maktab, QVP...)
  street_id -> master.streets              (ixtiyoriy: ko'cha ta'miri)
  created_by -> users (hokim yordamchisi), created_at, updated_at, deleted_at

micro_project_updates
  id, project_id, user_id, body, progress_percent, occurred_at, created_at

micro_project_files
  id, project_id, path, original_name, mime, size_bytes, uploaded_by, created_at
```

### 3.3 Yangi ma'lumotnomalar (`master` sxemasi)

```
contract_types      code, name_cyr, sort_order, is_active
project_categories  code, name_cyr, sort_order, is_active
```

`object_types` bilan bir xil naqsh. Kodlar (`code`) barqaror — hisobot va integratsiya shularga tayanadi; ko'rinadigan nom (`name_cyr`) o'zgarishi mumkin.

### 3.4 Hal qilinishi kerak: xonadon yozuvi yo'q bo'lsa

`houses` jadvali **dangasa to'ldiriladi** — hozir 1 ta qator, `master.buildings` da esa 405 464 ta. Yozuv birinchi surat yuklanganda paydo bo'ladi.

Shartnoma ham xonadonga bog'lanadi. Rais surat yuklanmagan uyga shartnoma yuklasa, `house_id` mavjud bo'lmaydi.

**Yechim:** `HouseResolver` xizmati — `master.buildings` dan `mahalla.houses` ga bitta yozuv ko'chiradi (kadastr raqami, koordinata, ko'cha). Surat yuklash ham, shartnoma yuklash ham shu bitta yo'ldan o'tadi. Hozir bu mantiq surat yuklash kodining ichida — ajratib olinadi.

Bu ikkinchi chaqiruvchi paydo bo'lgani uchun zarur, oldindan emas.

## 4. Rollar

Mavjud: `admin`, `deputat`, `mahalla-5ligi`, `masul-xodim`, `viloyat` (ko'ruvchi).

Qo'shiladi:

| Rol | Kim | Nima qiladi |
|---|---|---|
| `rais` | mahalla raisi | ijtimoiy shartnoma yuklaydi (xonadon kesimida) |
| `hokim-yordamchisi` | mahalla hokim yordamchisi | mikroloyiha yaratadi va jarayonni yozib boradi |

Yangi huquqlar: `contracts.view`, `contracts.manage`, `projects.view`, `projects.manage`.

Ko'rish huquqi boshqarish huquqidan ajratiladi — bu `MahallaScope` da `canSeeAll` bilan allaqachon qilingan naqsh. Rahbar hammasini ko'radi, hech narsani o'zgartirmaydi.

**Qamrov:** rais va hokim yordamchisi faqat **o'z mahallasi** doirasida ishlaydi. Bu `MahallaScope` orqali majburlanadi, so'rovda qo'lda emas — aks holda bitta unutilgan `where` boshqa mahallaning ma'lumotini ochib qo'yadi. (Avvalgi auditda aynan shunday IDOR topilgan edi.)

## 5. Fayl saqlash

Shartnoma — PDF, xonadon kesimida. Hozirgi surat saqlash `storage/app/` da, `mahalla/{mahalla_id}/{house_id}/` tuzilishida.

Shartnoma fayllari shu naqshni davom ettiradi: `contracts/{mahalla_id}/{contract_id}/`.

**Xavfsizlik talabi:** fayllar `public/` ga tushmaydi. Yuklab olish nazorat qilinadigan yo'l orqali (`GET /api/mahalla/contracts/{id}/file/{fileId}`), `MahallaScope` tekshiruvidan keyin. Shartnomada shaxsiy ma'lumot bor — to'g'ridan-to'g'ri havola bilan ochilishi mumkin emas.

Cheklovlar: faqat `application/pdf`, eng ko'pi 10 MB, `mime` server tomonda tekshiriladi (kengaytma bilan emas).

## 6. Bosqichlar

Har bir bosqich o'zi ishlaydigan holatda tugaydi.

### 1-bosqich — ko'rsatkichlar va yangi bosh sahifa
- `mahalla_indicators` panelda ko'rinadi: aholi, oila, reestr qamrovi, kambag'allik
- og'ir mahalla va «Янги Ўзбекистон» belgilari
- ikki maxraj ajratilgan holda (2-bo'limga muvofiq)
- ijtimoiy obyektlar xaritada (113 ta allaqachon bazada)

*Nega birinchi:* ma'lumot allaqachon bazada, faqat ko'rsatilmagan. Eng tez qiymat.

### 2-bosqich — ijtimoiy shartnoma
- `contract_types` ma'lumotnomasi
- `social_contracts` + `contract_files`
- `rais` roli, `HouseResolver` ajratib olinadi
- yuklash sahifasi (xonadon qidirish → PDF yuklash)
- panelda: shartnoma soni, qamrov

### 3-bosqich — mikroloyiha
- `project_categories`
- `micro_projects` + yangilanishlar + fayllar
- `hokim-yordamchisi` roli
- loyiha kartochkasi, jarayon tarixi
- panelda: mahalla kesimida loyihalar, byudjet ijrosi

## 7. Sinov

Mavjud qoidalar saqlanadi va yangi kodga ham tegishli:

- `kbt` — **umumiy dev bazasi**. `migrate:fresh`, `db:wipe`, `migrate:rollback` **taqiqlanadi**. Faqat oldinga `migrate`.
- Testlar faqat `DatabaseTransactions`. `TestCase` dagi ikki qo'riqchi (taqiqlangan trait va production bloki) o'z kuchida.
- Har bir yangi rol uchun **qamrov testi**: boshqa mahalla ma'lumotiga murojaat 403 qaytarishi shart. Bu «yozilsa yaxshi» emas — avvalgi auditda aynan shu sinf xatosi topilgan.
- Fayl yuklashda: noto'g'ri `mime`, hajm oshib ketishi, begona mahalla shartnomasini yuklab olishga urinish.

## 8. Ochiq savollar

1. **Shartnoma summasi** kerakmi? Diagrammada ko'rsatilmagan. Kerak bo'lsa — valyuta va sana bilan (so'm qadrsizlanadi, tarixiy taqqoslash uchun).
2. **Mikroloyiha byudjeti** qayerdan: mahalla, tuman, viloyat? Manba ustuni kerak bo'lishi mumkin.
3. **Shartnoma holati** qanday qiymatlarni oladi (imzolangan / bajarilmoqda / yakunlangan / bekor qilingan)?
4. Qolgan 36 mahalla uchun aholi va xonadon soni — hozircha faqat 16 og'ir mahallada bor.
