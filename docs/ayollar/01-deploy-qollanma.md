# «Ayollar Balansi» — ishlab chiqarishga joylashtirish

> **DEPLOY QILINMAGAN.** Bu hujjat — qadamlar ro'yxati. Joylashtirish
> alohida topshiriq bilan bajariladi (local-first qoidasi).

Server: `192.168.0.252` (Ubuntu 26.04), backend `/var/www/app`,
SSH: `ssh -i ~/.ssh/kbt_deploy xbt@192.168.0.252` (root emas).

---

## 0. Oldindan qaror talab qiladigan narsalar

| Savol | Holat |
|---|---|
| Anketa savollarining MATNI | **YO'Q** — promtda faqat raqamlar. `rules.json` da semantik kalitlar bor, matn kelganda `src/lib/questions.ts` kengaytiriladi |
| Migratsiya savolining raqami | `q13` deb belgilangan (III bo'lim). **Tasdiqlash kerak** |
| Figma ekranlari (22 ta) | Faylda **yo'q** — faqat `01 · Fondation`. Qo'shilsa, ekranlar 1:1 qayta ko'chiriladi |
| PII kaliti | Prodda alohida fayl yaratilishi kerak (1-qadam) |

---

## 1. PII shifrlash kaliti (BIRINCHI QADAM)

Kalit `.env` da EMAS, alohida faylda. `AYOLLAR_PII_KEY_PATH` belgilanmasa,
tizim APP_KEY'ga tushadi — bu faqat lokal ishlab chiqish uchun.

```bash
sudo mkdir -p /etc/ayollar
openssl rand -base64 48 | sudo tee /etc/ayollar/pii.key > /dev/null
sudo chown www-data:www-data /etc/ayollar/pii.key
sudo chmod 0400 /etc/ayollar/pii.key
```

`.env` ga:

```
AYOLLAR_PII_KEY_PATH=/etc/ayollar/pii.key
AYOLLAR_QR_BASE_URL=https://ayollar.digital-xorazm.uz
```

> **DIQQAT:** kalit almashtirilsa, mavjud shifrmatnlar O'QILMAY QOLADI.
> Kalit fayli zaxira nusxaga (baza zaxirasidan ALOHIDA joyda) olinishi shart.

---

## 2. Backend

```bash
cd /var/www/app
git fetch origin && git checkout feature/ayollar-balansi   # yoki main'ga birlashtirilgandan keyin
composer install --no-dev --optimize-autoloader

php artisan migrate --force                                 # forward-only, down() yo'q
php artisan db:seed --class=SystemsSeeder --force            # auth.systems -> `ayollar`
php artisan db:seed --class="App\Domains\Ayollar\Database\Seeders\MetricRegistrySeeder" --force
php artisan db:seed --class=RolePermissionSeeder --force
php artisan permission:cache-reset

php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.4-fpm
```

**MIGRATSIYA XAVFSIZLIGI:** `migrate:fresh` va `rollback` TAQIQ — baza
umumiy, unda 7 boshqa modul yashaydi. Ayollar migratsiyalarida `down()`
ataylab yo'q.

### `.env` qo'shimchalari

```
SANCTUM_STATEFUL_DOMAINS=...,ayollar.digital-xorazm.uz
FRONTEND_ORIGINS=...,https://ayollar.digital-xorazm.uz
SESSION_DOMAIN=.digital-xorazm.uz
```

---

## 3. Frontend

```bash
cd /d/kadr/ayollar                 # lokalda
bun install && bun run build       # dist/

rsync -az --delete dist/ xbt@192.168.0.252:/var/www/ayollar/
```

`.env.production` — `VITE_API_URL` **bo'sh** (same-origin, relative).

---

## 4. nginx vhost

`yoshlar` vhostidan nusxa, `s/yoshlar/ayollar/`.

```nginx
server {
    listen 443 ssl http2;
    server_name ayollar.digital-xorazm.uz;

    ssl_certificate     /etc/letsencrypt/live/ayollar.digital-xorazm.uz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ayollar.digital-xorazm.uz/privkey.pem;

    root /var/www/ayollar;
    index index.html;

    # SAME-ORIGIN: /api, /sanctum, /storage -> Laravel.
    # Shu tufayli CORS, OPTIONS preflight va uchinchi tomon cookie'si
    # umuman qatnashmaydi.
    location ~ ^/(api|sanctum|storage)/ {
        root /var/www/app/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        root /var/www/app/public;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # Service worker KESHLANMAYDI — aks holda yangi versiya
    # planshetlarga hafta davomida yetib bormasdi.
    location = /sw.js {
        add_header Cache-Control "no-cache, no-store, must-revalidate";
    }

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

TLS:

```bash
sudo certbot certonly --webroot -w /var/www/html -d ayollar.digital-xorazm.uz
sudo nginx -t && sudo systemctl reload nginx
```

---

## 5. Rejalashtirilgan ish

`ayollar:recalculate` allaqachon `routes/console.php` da (`03:30`).
Server cron'ida Laravel scheduler ishlab turganini tekshirish:

```bash
crontab -l | grep schedule:run
```

---

## 6. Hisoblar

```bash
php artisan ayollar:make-user LOGIN \
  --name="F.I.Sh." --role=ROL --district="Tuman" --mahalla="MFY" --org=IDORA
```

Rollar: `mfy_activist`, `mfy_chairman`, `hokim_assistant`,
`district_family_dept`, `district_org` (`--org` bilan),
`region_analyst`, `admin`.

> **MFY darajasidagi rolga `--mahalla` MAJBURIY.** Buyruq buni
> tekshiradi: doirasiz foydalanuvchi kira oladi, lekin hech narsa
> ko'rmaydi — bu holat oldingi modullarda bir necha marta takrorlangan.

**Prodda `ayollar:demo` YURITILMAYDI** — buyruq `APP_ENV=production` da
o'zi to'xtaydi.

---

## 7. Planshet (Flutter)

```bash
cd /d/kadr/ayollar_mobile
flutter build apk --release \
  --dart-define=AYOLLAR_URL=https://ayollar.digital-xorazm.uz
```

APK planshetlarga qo'lda o'rnatiladi. Qobiq **yiliga bir marta**
yangilanadi — barcha o'zgarishlar veb tomonda.

---

## 8. Deploydan keyingi tekshiruv

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://ayollar.digital-xorazm.uz/          # 200
curl -s -o /dev/null -w "%{http_code}\n" https://ayollar.digital-xorazm.uz/api/ayollar/context  # 401
curl -s https://ayollar.digital-xorazm.uz/api/ayollar/public/a/ZZZZZZ | head -c 100  # 404, valid:false
```

Brauzerda: kirish -> boshqaruv paneli raqamlari -> reyestr -> anketa
kartochkasi («Toifa qanday aniqlandi» bloki) -> QR sahifasi (shaxsiy
ma'lumot **yo'q**).
