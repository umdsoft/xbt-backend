# Cloudflare va ma'lumot oqimi — texnik xulosa (huquqiy baholash uchun)

> Sana: 2026-08-03. Maqsad: `digital-xorazm.uz` platformasi Cloudflare (CF) orqali
> ishlaganda fuqaro ma'lumotlari qayerdan o'tishini va O'zbekiston qonunchiligiga
> (ZRU-547 "Shaxsga doir ma'lumotlar to'g'risida") mosligini **huquq bo'limi** baholashi
> uchun texnik tavsif. Barcha faktlar 2026-08-03 da o'lchov/tekshiruv bilan tasdiqlangan.
> **Bu — huquqiy xulosa emas; texnik asos. Yakuniy qarorni huquq bo'limi/vakolatli organ chiqaradi.**

## 1. Joriy arxitektura (tekshirilgan)
- **Origin (asosiy) server:** O'zbekistonda — ommaviy IP `89.249.62.67` (LAN `192.168.0.252`). Barcha `*.digital-xorazm.uz` shu serverда.
- **Ma'lumotlar bazasi (PostgreSQL `xorazm`) va fayllar:** origin serverда, O'zbekistonда. **Cloudflare bazani nusxalamaydi/saqlamaydi.**
- **Cloudflare rejimi:** `app`, `mahalla`, `hr`, `advisor`, `sport`, `xbt` sub-domenlari — **Proxied (orange)** (trafik CF orqali). `ftp`, `mail`, `webmail` — DNS-only (CF'siz).
- **CF dataмarkazi (colo):** O'zbekiston trafigи uchun — **Varshava (WAW), Polsha** (CF-RAY: `...-WAW`).
- **TLS:** foydalanuvchi ↔ CF shifrланган; **CF Varshavaда TLS'ни ochadi (terminatsiya)**; keyin CF ↔ origin qayta shifrланган (Full/Strict); origin **CF-lock** (faqat CF IP'lari kiradi — to'g'ridan-to'g'ri kirish HTTP 000).

## 2. Qanday ma'lumot, qayerда
### Serverда (O'zbekistonда) — CHIQMAYDI
- Butun ma'lumotlar bazasi (fuqaro ma'lumotlari, KPI, topshiriq, hisobot, fayl-dalillar).
- Fayllar (tasdiqlovchi hujjatlar, foto-dalillar) — maxfiy diskда, origin'да.

### Transitда (CF Varshava orqali) — OCHIQ (shifrsiz) HOLATDA O'TADI
CF proxy TLS'ни chekkada ochgani uchun **har so'rov/javob tanasi** Varshava dataмarkazida bir lahza ochiq ko'rinadi:
- **Mahalla domeni:** fuqaro ma'lumotlari (PINFL, pasport, JSHSHIR — shifrланган saqlanadi, lekin API javobida qaytса, transitда ochiladi).
- Login parollari (POST tanasида), sessiya cookie'lari.
- Advisor/HR/Sport domeni ma'lumotlari.

### Cloudflare NIMANI saqlaydi / saqlamaydi
- **Saqlamaydi:** dinamik API javoblari (`cf-cache-status: DYNAMIC` — keshlanmaydi). Baza/fayllar nusxasi.
- **Keshlaydi (chekkada):** faqat statik JS/CSS (immutable) — ularда PII **yo'q**.
- **Metama'lumot logi:** IP, URL, status kodi, vaqt (javob **tanasi** emas — Free rejimда). CF — AQSh kompaniyasi (ma'lumot chet el huquqiy so'rovlariga bo'ysunishi mumkin).

## 3. Xavf / masala (huquqiy jihat)
- **Chegaralararo (cross-border) qayta ishlash:** fuqaro shaxsiy ma'lumoti Polsha (chet el yurisdiksiyasi) dataмarkazида ochiq holatда qayta ishlanadi (marshrutlash/filtrlash).
- **ZRU-547 (Shaxsga doir ma'lumotlar to'g'risida):** fuqarolar ma'lumoti **O'zbekistondagi bazaда** saqlanishi shart — bu **bajarilган** (baza O'zbekistonда). Ammo chegaralararo **uzatish/qayta ishlash** alohida talablarga ega (yetarli himoya/rozilik/shartlar). CF orqali ochiq tranzit — potensial nomuvofiqlik / kulrang zona.
- **Xulosa:** baza chiqib ketmaydi; lekin maxfiy trafik chet el DC orqali ochiq o'tadi — buni huquq bo'limi ZRU-547 va idoraviy talablarga solishtirib baholashi kerak.

## 4. Xavf ostiда EMAS (muvozanat uchun)
- Baza chet elга nusxalanmaydi.
- Trafik foydalanuvchi↔CF va CF↔origin shifrланган (faqat CF ichида bir lahza ochiq).
- CF — nufuzli provayder (SOC 2, ISO 27001, GDPR DPA mavjud).
- Origin CF-lock + UFW + fail2ban + nginx himoyasi faol (hujum-skanerlар bloklanmoqda).

## 5. Variantlar
| Variant | Ma'lumot yo'li | Xavfsizlik | Tezlik | Qonun |
|---|---|---|---|---|
| **A. API'ni grey-cloud** (app DNS-only) | PII to'g'ridan-to'g'ri origin (O'zbekistonda qoladi) | CF DDoS/WAF yo'q → origin himoyasi kuchaytiriladi; CF-lock olib tashlanadi | Tezroq (Varshava aylanmasi yo'q) | Eng mos |
| **B. Hozirgidek (CF orange)** | PII Varshava orqali ochiq tranzit | CF DDoS/WAF kuchli | Sekinroq | Kulrang zona |
| **C. Gibrid** | API grey (PII yo'li), statik SPA orange (PII yo'q) | API origin himoyasида, statik CF'да | API tez, statik keshdan | API mos |
| **D. CF Data Localization Suite** | Hudud cheklovi | CF to'liq | — | Enterprise (pullik); O'zbekiston hududi yo'q |

## 6. Texnik tavsiya (yakuniy qaror — huquq bo'limi)
Fuqaro PII + mahalliy foydalanuvchi + mahalliy origin + ZRU-547 + tezlik — **A yoki C** foydasига:
- **API (`app.digital-xorazm.uz`) ни grey-cloud** qilinса, PII Varshavaга chiqmaydi (O'zbekistonда qoladi) VA kechikish yo'qoladi. Narxi: API'да CF DDoS/WAF o'rniga origin himoyasini kuchaytirish (nginx rate-limit + fail2ban + kerak bo'lса mahalliy WAF, IP-limit).
- Statik SPA (advisor va h.k.) — PII yo'q, CF orange qolса bo'ladi (keshdan tez).
- Agar CF (B) saqlanса — tezlik uchun CF Argo (pullik), lekin chegaralararo tranzit masalasi qoladi.

## 7. Ilova — tekshirilган dalillar (2026-08-03)
- Origin-lock faol: to'g'ridan-to'g'ri `89.249.62.67` → `HTTP 000` (faqat CF IP).
- CF colo: `CF-RAY: ...-WAW` (Varshava).
- App tezligi (serverда, localhost): `/api/advisor/me` **~3 ms**; statik SPA ~2.5 ms.
- CF orqali (foydalanuvchi): API TTFB **550–920 ms** (≈99% — CF Varshava↔origin masofasi).
- Statik asset: `cf-cache-status: HIT` (CF chekkасида keshlanган); dinamik API: `DYNAMIC` (keshlanmaydi).
- CORS `Access-Control-Max-Age: 3600` (preflight keshlanadi).

---
*Hujjat lokal saqlanган (D:\kadr\platform\docs). Hech qanday CF/server sozlamasi O'ZGARTIRILMADI — bu faqat baholash uchun.*
