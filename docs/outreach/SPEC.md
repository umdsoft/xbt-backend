# Texnik topshiriq: MCP asosidagi outreach tizimi

Holati: 2026-09-27. Loyiha egasi: Umidbek Jumaniyozov.

## 0. Muhim o'zgarish: CRM advisor platformasi ichida

Bu hujjatdagi "ma'lumotlar sayti" — maslahatchilar ishlatadigan **advisor platformasi**. CRM alohida sayt sifatida qurilmaydi, balki shu platforma ichida modul bo'ladi.

Bundan kelib chiqadigan qoidalar:

- **Kod uslubi.** Jadvallar, modellar, rollar, UI va API platformaning mavjud arxitekturasi va uslubiga moslab quriladi.
- **Tasdiqlash faqat platformada.** Xat seriyasini tasdiqlash platforma UI'sida, tizimga kirgan va huquqi bor maslahatchi tomonidan bajariladi. `approved_by` — shu foydalanuvchining ID'si. MCP'da **tasdiqlash tool'i bo'lmaydi**: Claude hech qachon o'zi tasdiqlay olmaydi.
- **Egalik va ko'rinish.** Har bir lidning egasi (maslahatchi) va hududi bo'ladi. Kim nimani ko'rishi va tahrirlashi platformaning mavjud rollariga bog'lanadi.
- **MCP ulanishi.** MCP server platformaga har bir maslahatchi uchun alohida, minimal huquqli token bilan ulanadi (API orqali yoki platforma ichidagi MCP endpoint orqali — kod bazasini o'rganib tanlanadi).

## 1. Maqsad

Nishon davlatlardagi IT kompaniyalarni topish va Xorazmda filial yoki xizmat ko'rsatish markazi ochishga taklif qilish. Tizim ularni onlayn uchrashuvga yozilgunicha olib boradi. Nishon davlatlar ro'yxati `docs/outreach/country-analysis.md` faylida.

Tizimning vazifalari:

1. Kompaniyalarni va ulardagi qaror qiluvchilarni topish, xatosiz email manzillarini aniqlash.
2. Ularni ma'lumotlar saytiga davlat va hudud kesimida lid sifatida yozish.
3. Davlat tilida shaxsiylashtirilgan xat seriyasini tayyorlash; inson tasdiqlagach, korporativ pochta orqali yuborish.
4. Javoblarni o'qish, tasniflash va lid bosqichini yangilash.
5. Qiziqqan kompaniyalar bilan onlayn uchrashuv belgilash.

## 2. Arxitektura

```
Tadqiqot MCP ──kontaktlar──▶ Claude (agent) ◀──lid, bosqich──▶ Ma'lumotlar sayti MCP (CRM)
                                  │  ▲                                   │
                              qoralama│  │javoblar                         └──▶ Kalendar
                                  ▼  │
                          Inson tasdig'i (maslahatchi)
                                  │ tasdiqlangan
                                  ▼
                            Pochta MCP (invest.digital-xorazm.uz) ──xat──▶ Xorijiy kompaniya
                                  ▲─────────────────────javob──────────────────┘
```

Transport:
- Lokal ishlash uchun (Claude Code / Claude Desktop): `stdio`.
- Serverda ishlash uchun: Streamable HTTP va autentifikatsiya.

Qaysi biri tanlanishi 8-bo'limdagi savollarga bog'liq.

Til: MCP serverlari uchun rasmiy SDK bor TypeScript tavsiya etiladi. Boshqa til (masalan, PHP/Laravel) tanlansa, ishni boshlashdan oldin kelishib olinadi.

## 3. Ma'lumotlar modeli (CRM)

Ma'lumotlar sayti o'z bazasi bilan ishlaydi. Quyidagi jadvallar mavjud bo'lmasa, migratsiya bilan yaratiladi. Nomlar taxminiy — mavjud sxemaga moslashtiring.

| Jadval | Asosiy maydonlar |
| --- | --- |
| `countries` | `code`, `name`, `wave` (1/2/investor), `score`, `excluded` (bool), `excluded_reason` |
| `companies` | `id`, `name`, `domain` (unique), `country_code`, `region_city`, `employees`, `industry`, `has_offshore_center`, `open_roles_6m`, `client_regions`, `languages`, `source`, `icp_score` (0–100), `tier` (A/B/C), `sanctions_status` (clear/hit/unchecked), `stage`, `created_at`, `updated_at` |
| `contacts` | `id`, `company_id`, `full_name`, `title`, `role_type` (ceo/coo/cto/delivery/expansion), `email`, `email_status` (verified/catch_all/invalid/unknown), `verified_at`, `linkedin_url`, `language`, `unsubscribed_at`, `do_not_contact` |
| `messages` | `id`, `contact_id`, `sequence_step` (1/2/3), `language`, `subject`, `body`, `body_hash`, `status` (draft/approved/sent/bounced/replied/cancelled), `approved_by`, `approved_at`, `scheduled_for`, `sent_at`, `smtp_message_id` |
| `replies` | `id`, `message_id`, `from_email`, `received_at`, `classification` (interested/later/declined/auto_reply/unsubscribe/bounce), `summary` |
| `meetings` | `id`, `company_id`, `contact_id`, `proposed_slots`, `start_at`, `meeting_link`, `status`, `notes` |
| `audit_log` | `id`, `actor` (user/claude/system), `action`, `entity`, `entity_id`, `payload_json`, `created_at` |

Lid bosqichlari (`companies.stage`):
1. `found`
2. `verified`
3. `awaiting_approval`
4. `sent`
5. `replied`
6. `meeting_booked`
7. `meeting_done`
8. `visit_or_mou`
9. `resident_or_office`

Yopiq holatlar: `closed_declined`, `closed_unsubscribed`, `blocked_sanctions`.

## 4. MCP serverlar va tool'lar

### 4.1 CRM MCP (1-bosqich)

| Tool | Vazifa | Eslatma |
| --- | --- | --- |
| `upsert_company` | Kompaniya qo'shish yoki yangilash (`domain` bo'yicha) | Chiqarilgan davlatlar rad etiladi |
| `upsert_contact` | Kontakt qo'shish yoki yangilash | Bitta kompaniyada faol kontaktlar soni ≤ 2 |
| `dedupe_check` | Domen yoki email bo'yicha dublikatni tekshirish | Faqat o'qish |
| `set_stage` | Lid bosqichini o'zgartirish | Faqat ruxsat etilgan o'tishlar |
| `log_touch` | Har bir aloqani yozish | |
| `get_pipeline` | Davlat va hudud kesimida filtr bilan ro'yxat | Faqat o'qish |
| `list_approvals` | Tasdiq kutayotgan qoralamalar | Faqat o'qish |
| `get_stats` | Davlat va hudud kesimida 6 ta ko'rsatkich: yuborilgan, yetib borgan, javob ulushi, qiziqqan, o'tgan uchrashuv, rezident | Faqat o'qish |

O'chirish tool'i yo'q. Har bir yozish amali `audit_log` ga tushadi.

### 4.2 Tadqiqot MCP (2-bosqich)

| Tool | Vazifa |
| --- | --- |
| `search_companies` | Apollo orqali filtrlar bilan qidirish: davlat, soha, xodimlar soni 50–2 000 |
| `find_people` | Kompaniyadagi qaror qiluvchilarni topish (rollar pastda) |
| `verify_email` | Sintaksis, domen MX yozuvi va SMTP tekshiruvi; catch-all domenni aniqlash |
| `screen_sanctions` | OFAC SDN va YeI konsolidatsiyalangan ro'yxatlariga solishtirish; ro'yxatlar mahalliy saqlanadi va muntazam yangilanadi |
| `score_lead` | Pastdagi ICP jadvali bo'yicha 0–100 ball va toifa |

Apollo'ning o'z MCP yoki API'si bo'lsa, undan foydalaning; qayta yozmang.

**Qaror qiluvchilar:**
- 200 xodimgacha: CEO yoki asoschi.
- 200 dan ortiq: COO, VP Delivery yoki Head of Global Delivery.
- R&D markazi uchun: CTO yoki VP Engineering.
- Har qanday hajmda: Head of Expansion yoki Business Development.

**ICP ballash (jami 100):**

| Belgi | Ball |
| --- | --- |
| Xodimlar soni 50–2 000 | 0–15 |
| Allaqachon offshore yoki nearshore markazi bor | 0–15 |
| So'nggi 6 oyda ko'p ochiq vakansiya yoki o'sish | 0–15 |
| Mijozlari AQSh yoki YeIda | 0–10 |
| Soha: outsourcing, BPO/KPO, logistika dispetcherlik, agro-IT, gamedev | 0–15 |
| Rus, turk yoki ingliz tilida ishlaydi | 0–10 |
| Qaror qiluvchining tasdiqlangan emaili topilgan | 0–20 |
| Sanksiya ro'yxatida | stop |

**Toifalar:**
- A — 70 va undan yuqori ball.
- B — 50–69 ball.
- C — 50 dan past: bazada qoladi, xat yuborilmaydi.

Nishon mezoni sifatida IT Park **Zero Risk** dasturi shartlari olinadi:
- kamida 50 xodim;
- eksport shartnomasi kamida $500 000 **yoki** bosh kompaniya yillik aylanmasi $50 mln dan yuqori.

### 4.3 Pochta MCP (3-bosqich)

| Tool | Vazifa | Cheklov |
| --- | --- | --- |
| `create_draft` | Qoralama yaratish (`messages.status = draft`) | Yubormaydi |
| `list_replies` | IMAP orqali yangi javoblarni o'qish | Faqat o'qish |
| `get_thread` | Yozishmani to'liq ko'rish | Faqat o'qish |
| `send_approved` | Tasdiqlangan xabarni SMTP orqali yuborish | Pastdagi shartlarga qarang |
| `mark_unsubscribed` | Kontaktni obunadan chiqarish | Qaytarib bo'lmaydi |

`send_approved` quyidagi shartlarni server kodida tekshiradi:
- `status = approved`;
- `approved_by` bo'sh emas;
- joriy `body_hash` tasdiqlangandagisiga teng;
- kontakt `do_not_contact` emas va obunadan chiqmagan;
- kompaniya `sanctions_status = clear`;
- kunlik limit oshmagan.

Seriya: 0-, 4- va 10-kunlar. Javob kelishi bilan qolgan xatlar `cancelled` bo'ladi.

**Har bir xatda bo'lishi shart:**
- `List-Unsubscribe` va `List-Unsubscribe-Post` sarlavhalari (RFC 8058, bir bosishda obunadan chiqish);
- tanada obunadan chiqish havolasi;
- haqiqiy ism, lavozim, hokimlik nomi va manzil.

Qaytgan xatlar (bounce) ushlanadi, kontakt `invalid` deb belgilanadi. Ichki maqsad — qaytgan xatlar 2% dan past bo'lishi.

### 4.4 Kalendar (4-bosqich)

- Tool'lar: `suggest_time` (3 ta vaqt taklif qiladi) va `create_event` (onlayn uchrashuv havolasi bilan).
- Uchrashuv faqat kompaniya vaqtni tanlagandan keyin yaratiladi.
- Qaysi xizmat ishlatilishi 8-bo'limdagi savolga bog'liq: Google Calendar yoki Cal.com.

## 5. Inson nazorati nuqtalari

Quyidagi 4 ta qaror faqat egasiniki:

1. Xat seriyasini tasdiqlash yoki tahrirlash (3-bosqich).
2. Uchrashuv vaqtini tasdiqlash (6-bosqich).
3. Uchrashuvdan keyingi xatni tasdiqlash (7-bosqich).
4. Tashrif yoki memorandum (8-bosqich).

"Qiziqdi" deb tasniflangan javob darhol egasiga bildiriladi.

## 6. Pochta yetkazib berish

- Sovuq xatlar alohida subdomendan ketadi (masalan, `invest.digital-xorazm.uz`). Asosiy domenning obro'si himoyalanadi.
- DNS sozlamalari:
  - SPF;
  - DKIM;
  - DMARC — boshida `p=none`, From domeni bilan moslashgan;
  - to'g'ri PTR (reverse DNS) yozuvi;
  - TLS.
- Qizdirish: kichik kunlik hajmdan boshlanadi va 3–4 hafta davomida asta oshiriladi. Limit konfiguratsiyada saqlanadi.
- Spam shikoyatlari 0,1% dan past bo'lishi kerak va hech qachon 0,3% ga yetmasligi kerak. Bu Gmail'ning ommaviy yuboruvchilarga qo'yadigan talabi.

## 7. Qabul mezonlari (har bosqich uchun)

- **Testlar:** har bir tool uchun unit test yoziladi. `send_approved` ning barcha rad etish holatlari test bilan qoplanadi:
  - tasdiqlanmagan;
  - matni o'zgargan;
  - obunadan chiqqan;
  - sanksiya ro'yxatida;
  - limit oshgan.
- **Sinov rejimi:** ishlab chiqish paytida SMTP haqiqiy manzillarga emas, test pochta qutisiga (masalan, Mailpit) yuboradi.
- **Hujjat:** `README.md` da ishga tushirish, `.env.example` va MCP klientiga ulash konfiguratsiyasi bo'ladi.
- **Tekshiruv:** har bir bosqich oxirida MCP Inspector yoki Claude orqali qo'lda sinov o'tkaziladi.

## 8. Ochiq savollar (ishni boshlashdan oldin egasidan so'rang)

**Pochta:**
1. Server dasturi qaysi (Postfix/Dovecot, Exchange, Zimbra yoki boshqa)?
2. IMAP va SMTP host, port va autentifikatsiya qanday?
3. Subdomen va DNS yozuvlarini kim boshqaradi?

**Ma'lumotlar sayti:**
4. Qaysi texnologiyada ishlaydi (Laravel?) va qaysi bazada (MySQL yoki PostgreSQL)?
5. API bormi yoki MCP bazaga to'g'ridan-to'g'ri ulanadimi?
6. Kompaniyalar va lidlar uchun mavjud jadvallar bormi?
7. Sayt qayerda joylashgan (hosting)?

**Apollo:**
8. Tarif qaysi, oylik eksport va kredit limiti qancha, API kalit bormi?

**Ishga tushirish:**
9. MCP serverlar qayerda ishlaydi: egasining kompyuterida (`stdio`) yoki hokimlik serverida (HTTP)?

**Tasdiqlash:**
10. Kim tasdiqlaydi: faqat egasi yoki jamoa ham?
11. Xat imzosida qaysi ism va lavozim bo'ladi?

**Kalendar:**
12. Google Calendar yoki Cal.com? Uchrashuv platformasi qaysi (Google Meet, Zoom yoki Teams)?

**Taklif matni:**
13. Hokimlik Zero Risk'dan tashqari qanday imtiyoz taklif qila oladi (Urganch IT Park filialida ofis va boshqalar)?

**Huquq:**
14. Rossiya, Xitoy, Koreya, Yaponiya va YeI'da sovuq B2B xat qoidalari hamda shaxsiy ma'lumotlarni saqlash bo'yicha yurist xulosasi bormi? Turkiya uchun 6563-son qonunning 6(2)-moddasiga ko'ra tacirlarga oldindan roziliksiz yozish mumkin, lekin rad etish imkoni majburiy.
