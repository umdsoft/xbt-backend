# «Свод жадваллар» moduli — texnik spec (dizayn)

> Sana: 2026-08-04. Maqsad: qaror/farmonlar ijrosini monitoring qiladigan, doimo
> ko'payib turadigan va kundalik so'raladigan svod jadvallar tizimi. Advisor domeni
> (`app/Domains/Advisor`), `advisor` schema. Dizayn foydalanuvchi tomonidan tasdiqlangan.

## 1. Maqsad
Har bir qaror/farmon ijrosi = bitta svod jadval: 13 tuman × maxsus foizli ustunlar +
holat + UMUMIY TAYYORLIK. Yangi svod uchun KOD YOZILMAYDI — viloyat jadval yaratadi,
ustunlarni (og'irliklari bilan) belgilaydi, tumanlar to'ldiradi, viloyat tasdiqlaydi,
tizim avtomatik hisoblab Excelга chiqaradi.

## 2. Ma'lumotlar modeli (advisor schema, 4 jadval)
### monitoring_sheets
- id (uuid), title, basis (asos/subtitle), reference_no, reference_date (date, nullable),
  category (qaror|farmon|pq|farmoyish|other), as_of_date (date, holatiga),
  completion_threshold (tinyint, default 100 — «Ishga tushgan» %),
  status (active|archived, default active), created_by (uuid), timestamps, softDeletes.

### monitoring_metrics (svod ustunlari)
- id, sheet_id (idx), name, unit (default '%'), weight (decimal 5,2 — % ulush), sort_order.
- Bir svodда 1..N ustun. Og'irliklар yig'indisi ~100 (validatsiya; teng bo'lса oddiy o'rtacha).

### monitoring_entries ((svod × tuman) satri)
- id, sheet_id (idx), district_id (uuid), note (text nullable),
  review_status (draft|submitted|confirmed|returned, default draft),
  submitted_at, submitted_by, confirmed_at, confirmed_by, return_comment (text nullable),
  updated_by, timestamps. unique(sheet_id, district_id).

### monitoring_values ((entry × ustun) qiymati)
- id, entry_id (idx), metric_id (idx), value (decimal 5,2, 0..100). unique(entry_id, metric_id).

## 3. Hisoblash (aynan Excel formulasi)
- **UMUMIY TAYYORLIK (tuman)** = Σ(value_i × weight_i) ÷ Σ(weight_i). (og'irliklar teng bo'lса — oddiy o'rtacha.)
- **Ijro holati (avtomatik):** 0% → Boshlanmagan 🔴; 1–99% → Jarayonda 🟡; ≥threshold → Ishga tushgan 🟢.
- **JAMI/O'RTACHA:** har ustun bo'yicha 13 tuman o'rtachasi; umumiy = UMUMIY ustuni o'rtachasi.
  (yozuvsiz tuman = 0.)

## 4. Rollar va oqim
- **Tuman:** BUTUN 13 tuman svodини ko'radi (shaffoflik), FAQAT o'z satrини (foizlar+izoh)
  tahrirlaydi va **yuboradi** (draft→submitted). Tasdiqlangач qulflanadi; qayta tahrir → submitted.
- **Viloyat:** svod + ustunlarни yaratadi/tahrirlaydi (og'irliklar); butun svodни ko'radi;
  submitted satrни **tasdiqlaydi** (confirmed) yoki **qaytaradi** (returned+izoh); qiymatларни
  O'ZGARТИРМАЙДИ (faqat tasdiq); Excel eksport; statistika.
- **Bo'linма:** faqat ko'rish + eksport.

## 5. Ruxsatlar (AdvisorAccess)
- `monitoring.view` — barcha rol. `monitoring.enter` — tuman (o'z satri).
  `monitoring.manage` — viloyat (svod/ustun CRUD). `monitoring.confirm` — viloyat (tasdiq).
- viloyat `*` hammasini qamraydi; tuman: view+enter; bo'linма: view.

## 6. API (advisor prefix)
- `GET /monitoring` — svodlar ro'yxati (kategoriya/qidiruv/holat; har svodда umumiy% + tasdiq holati).
- `POST /monitoring` — yangi svod (viloyat) + ustunlar.
- `GET /monitoring/stats` — umumiy statistika (rolga qarab). ({sheet} dan OLDIN.)
- `GET /monitoring/{sheet}` — svod tafsiloti (13 tuman × ustunlar + UMUMIY + JAMI + tasdiq holati).
- `PATCH /monitoring/{sheet}` — svod meta/ustun tahrir (viloyat).
- `POST /monitoring/{sheet}/duplicate` — nusxa/shablon (viloyat).
- `POST /monitoring/{sheet}/entry` — tuman o'z satrini kiritadi/yuboradi (qiymatlar+izoh).
- `POST /monitoring/{sheet}/entries/{district}/confirm` — viloyat tasdiqlaydi.
- `POST /monitoring/{sheet}/entries/{district}/return` — viloyat qaytaradi (izoh).
- `GET /monitoring/{sheet}/export` — Excel (SimpleXlsx).

## 7. Frontend
- Nav: «Свод жадваллар» (barcha rol; viloyat — yaratish/tasdiq).
- Ro'yxat sahifasi: svodlar jadvali + kategoriya/qidiruv + «Янги свод» (viloyat).
- Svod tafsiloti: aynan Excel ko'rinishi (rangли holat, foiz ustunlar, UMUMIY, JAMI/O'RTACHA),
  tuman: o'z satri inline + «Юбориш»; viloyat: tasdiق/qaytариш + «Свод (Excel)» + ustun boshqaruvi.
- Statistika paneli (chora-tadbir naqshi).

## 8. Bosqichlar
1. Ma'lumot modeli (migratsiyalar) + models + ruxsatlar.
2. Service (create/list/detail/compute/upsertEntry/submit/confirm/return/stats) + controller + routes + testlar.
3. Excel eksport + nusxa/shablon.
4. Frontend (ro'yxat + tafsilot + kiritish + tasdiq + statistika + nav).

## 9. Tekshirilган asoslar
- Excel og'irligi teskari-muhandislik bilan aniqланди (20/35/45); formula tasdiqланди.
- `App\Support\SimpleXlsx` mavjud (tashqi kutubxonasiz eksport). advisor.districts (13 tuman) mavjud.
- Naqsh: KPI matritsa + chora-tadbir (statistika/rol/monitoring) qayta ishlatiladi.
