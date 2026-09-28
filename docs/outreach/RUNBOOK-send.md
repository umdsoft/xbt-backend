# Outreach — yuborishni ishga tushirish (runbook)

Maqsad: tasdiqlangan investor xatlarini hokimlikning o'z Mailcow serveri orqali xavfsiz
yuborishni yoqish. Har bir qadam oxirida tekshiruv bor. Loyiha: [PLAN-send.md](PLAN-send.md).

**Qoida:** `php artisan outreach:send-preflight` hamma qatorda PASS bermaguncha `live` rejim yoqilmaydi.

## 0. Hozirgi holat (2026-09-27)

| Qism | Holat |
|---|---|
| Yuborish kodi, navbat, himoya, obunadan chiqish, IMAP poller, MCP, UI | tayyor, testlangan (Mailpit bilan to'liq oqim) |
| `mail.digital-xorazm.uz` A → 89.249.62.68 | qo'shildi (Cloudflare) |
| PTR 89.249.62.68 → mail.digital-xorazm.uz | **One-Net'dan kutilmoqda** (xat yuborildi) |
| Spamhaus PBL | **ro'yxatda** — PTR'dan keyin chiqarish (2-qadam) |
| Davlatlar ro'yxati (`country-analysis.md`) | kutilmoqda |

## 1. PTR (One-Net)
Tekshiruv: `nslookup 89.249.62.68` → `mail.digital-xorazm.uz`. Preflight'da `PTR` qatori PASS.

## 2. Spamhaus PBL'dan chiqarish
https://check.spamhaus.org → `89.249.62.68` → PBL "More Info" → "I am running my own mail server".
ISP boshqaruvida bo'lsa — One-Net orqali. Chiqarish 1 yil amal qiladi (yangilash eslatmasi qo'yilsin).
Tekshiruv: sahifada PBL yo'q.

## 3. Gmail sinovi (umumiy pochta)
`php artisan outreach:send-preflight --test-to=<gmail manzil>` yoki Mailcow'dan oddiy xat.
Xat Gmail **Inbox**'ga tushishi kerak (Spam emas). Rad etilsa — javob matnini saqlab, qayta tahlil.

## 4. Mailcow: yuborish domeni va qutilar
1. Yuborish domeni: `invest.digital-xorazm.uz` (yoki egasi tanlagan domen) Mailcow'da domen sifatida.
2. DKIM kalitini Mailcow yaratadi → Cloudflare TXT `dkim._domainkey.invest…`.
3. DNS (Cloudflare, DNS only): MX `invest…` → `pochta.digital-xorazm.uz`;
   SPF `v=spf1 ip4:89.249.62.68 -all`; DMARC `v=DMARC1; p=none; rua=mailto:postmaster@digital-xorazm.uz`.
4. Jo'natuvchi qutilar (boshlanishiga 3–5 ta, keyin ~25 gacha).
5. Bitta **SMTP xizmat hisobi** — barcha pool qutilari nomidan yuborish huquqi bilan (Mailcow sender ACL).
6. Bitta **markaziy javoblar qutisi**; pool qutilari unga forward qiladi; bounce manzili ham shu.
7. Mailcow'da har quti uchun rate-limit (masalan 50/kun) — platformadagi limitdan yuqori, ikkinchi himoya.

## 5. Platforma `.env` (production)
```
OUTREACH_SMTP_HOST=<mailcow LAN host>   OUTREACH_SMTP_PORT=587   OUTREACH_SMTP_SCHEME=smtp
OUTREACH_SMTP_USERNAME=<xizmat hisobi>  OUTREACH_SMTP_PASSWORD=<.env da>
OUTREACH_INBOX_HOST=<mailcow LAN host>  OUTREACH_INBOX_USERNAME=<javoblar qutisi>  OUTREACH_INBOX_PASSWORD=<.env da>
OUTREACH_BOUNCE_ADDRESS=<javoblar qutisi>
OUTREACH_SENDER_NAME=…  OUTREACH_SENDER_TITLE=…  OUTREACH_SENDER_ORG=…  OUTREACH_SENDER_ADDRESS=…
OUTREACH_SEND_MODE=off
```
Pool qutilari UI'da: **Ҳорижий инвесторлар → Юбориш → Қути қўшиш** (qizdirish sanasi bilan).

## 6. Preflight
`php artisan outreach:send-preflight` — barcha qator PASS.

## 7. Test rejimi
`OUTREACH_SEND_MODE=test`, `OUTREACH_SEND_TEST_RECIPIENT=<o'z qutimiz>` — bir necha real seriyani
tasdiqlab, hammasi o'z qutimizga kelishini, sarlavhalar va obunadan chiqish havolasi ishlashini tekshirish.

## 8. Jonli rejim
`OUTREACH_SEND_MODE=live`. Birinchi 2 hafta har kuni "Юбориш" sahifasini kuzatish:
bounce < 2%, "Қарор кутмоқда" bo'sh, avtomatik to'xtatish yo'q. Hajm qizdirish jadvali bo'yicha o'zi oshadi.

## Favqulodda
- **To'xtatish:** "Юбориш" → "Паузага қўйиш" (navbat saqlanadi) yoki `.env` da `OUTREACH_SEND_MODE=off`.
- Avtomatik to'xtatish yoqilsa — sababi sahifada; bartaraf etilgach "Қайта ёқиш".
- Spamhaus/Gmail rad etishi ko'rinsa — darhol pauza, rasmiy pochta ham shu IP'da ekanini unutmang.
