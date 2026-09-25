# نصب روی سرور مجازی ایرانی

راهنمای قدم‌به‌قدم، از خرید سرور تا سایتی که روی دامنه‌ی خودتان با `https` بالا است.
حدود **یک تا دو ساعت** کار است. همه‌ی دستورها روی یک Ubuntu 24.04 تمیز تمرین شده‌اند.

در این راهنما هرجا `peygir.ir` آمده دامنه‌ی خودتان را بگذارید و هرجا `SERVER_IP`
آمده آی‌پی سرور را.

فایل‌های این پوشه:

| فایل | کجا می‌رود | چه می‌کند |
| --- | --- | --- |
| `nginx.conf` | `/etc/nginx/sites-available/peygir` | وب‌سرور |
| `php-fpm.conf` | `/etc/php/8.4/fpm/pool.d/peygir.conf` | اجرای PHP با کاربر خود برنامه |
| `supervisor.conf` | `/etc/supervisor/conf.d/peygir.conf` | صف (پردازش پاسخ‌های پیامکی) |
| `deploy.sh` | همین‌جا می‌ماند | هر به‌روزرسانی با یک دستور |
| `backup.sh` | همین‌جا می‌ماند | بکاپ شبانه‌ی دیتابیس |

---

## فهرست

- [۰. چه سروری بخرید](#۰-چه-سروری-بخرید)
- [۱. اولین ورود و امنیت پایه](#۱-اولین-ورود-و-امنیت-پایه)
- [۲. نصب نرم‌افزارها](#۲-نصب-نرمافزارها)
- [۳. دیتابیس](#۳-دیتابیس)
- [۴. گرفتن کد از گیت‌هاب](#۴-گرفتن-کد-از-گیتهاب)
- [۵. فایل ‎.env](#۵-فایل-env)
- [۶. اولین نصب](#۶-اولین-نصب)
- [۷. وب‌سرور و SSL](#۷-وبسرور-و-ssl)
- [۸. صف، کرون و بکاپ](#۸-صف-کرون-و-بکاپ)
- [۹. اولین ورود](#۹-اولین-ورود)
- [۱۰. روشن کردن پیامک آموت](#۱۰-روشن-کردن-پیامک-آموت)
- [۱۱. روشن کردن درگاه سامان](#۱۱-روشن-کردن-درگاه-سامان)
- [۱۲. هوش مصنوعی (اختیاری)](#۱۲-هوش-مصنوعی-اختیاری)
- [۱۳. به‌روزرسانی‌های بعدی](#۱۳-بهروزرسانیهای-بعدی)
- [۱۴. وقتی اینترنت بین‌الملل قطع است](#۱۴-وقتی-اینترنت-بینالملل-قطع-است)
- [عیب‌یابی](#عیبیابی)

---

## ۰. چه سروری بخرید

**سیستم‌عامل:** Ubuntu 24.04 LTS. **دیتاسنتر:** داخل ایران، با آی‌پی ثابت.

| | بدون هوش مصنوعی | با هوش مصنوعی (مدل ۷B) |
| --- | --- | --- |
| پردازنده | ۲ هسته | ۴ هسته یا بیشتر |
| رم | ۴ گیگ | **۸ گیگ** یا بیشتر |
| دیسک | ۴۰ گیگ SSD | ۶۰ گیگ SSD |

پیشنهاد: **با سرور کوچک شروع کنید و هوش مصنوعی را خاموش نگه دارید.** همه‌چیز بدون آن
کار می‌کند: ثبت سریع به فرم دستی برمی‌گردد و گزارش هفتگی از روی عددها نوشته می‌شود.
بعداً که مشتری آمد، رم را ارتقا دهید. مدل روی پردازنده (بدون کارت گرافیک) کند است و
استخراج یک پاراگراف ۲۰ تا ۶۰ ثانیه طول می‌کشد.

**دامنه:** یک دامنه بخرید (مثلاً `.ir` از nic.ir) و در پنل DNS دو رکورد بسازید:

| نوع | نام | مقدار |
| --- | --- | --- |
| A | `@` | `SERVER_IP` |
| A | `www` | `SERVER_IP` |

> ⏳ **از همین امروز شروع کنید، چون چند هفته طول می‌کشد:**
> - **درگاه سامان:** قرارداد پذیرندگی با بانک سامان. دامنه و **آی‌پی سرور** را از
>   شما می‌خواهند. شاپرک معمولاً **اینماد** را هم لازم دارد.
> - **پترن‌های آموت:** متن پترن‌ها باید در پنل تأیید شود. فقط پترن `otp` (کد ورود)
>   برای شروع لازم است.
>
> تا این دو آماده شوند سایت با پیامک «لاگ» و درگاه «آزمایشی» کامل کار می‌کند.

---

## ۱. اولین ورود و امنیت پایه

از ویندوز، در PowerShell:

```powershell
ssh root@SERVER_IP
```

روی سرور:

```bash
apt update && apt upgrade -y

# کاربر برنامه. وب، صف، کرون و به‌روزرسانی همه با همین کاربر اجرا می‌شوند.
adduser --disabled-password --gecos "" peygir

# دیوار آتش: فقط SSH و وب
ufw allow OpenSSH
ufw allow 80
ufw allow 443
ufw --force enable
```

> اگر `apt update` خیلی کند است، دیتاسنتر معمولاً یک **آینه‌ی داخلی** برای Ubuntu
> دارد. آدرسش را از پشتیبانی بپرسید و در `/etc/apt/sources.list.d/ubuntu.sources`
> جای `archive.ubuntu.com` بگذارید.

---

## ۲. نصب نرم‌افزارها

```bash
# PHP 8.4 (خود Ubuntu 24.04 نسخه‌ی 8.3 دارد، و این پروژه 8.4 لازم دارد)
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update

apt install -y nginx mysql-server supervisor git unzip curl \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath

# Composer
curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
php8.4 /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Node 22 (فقط برای ساختن CSS و JS)
curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
apt install -y nodejs
```

بررسی:

```bash
php -v          # باید 8.4 باشد
composer -V
node -v         # باید 22 باشد
```

> **اگر `add-apt-repository` یا nodesource وصل نشد:** بعضی دیتاسنترها دسترسی
> خارجی را محدود می‌کنند. اول از پشتیبانی بپرسید؛ معمولاً باز است. برای Node راه
> دور زدن هم دارید: اصلاً روی سرور نصبش نکنید و CSS و JS را روی کامپیوتر خودتان
> بسازید (بخش ۱۳ را ببینید).

---

## ۳. دیتابیس

```bash
mysql
```

داخل MySQL (رمز را عوض کنید):

```sql
CREATE DATABASE peygir CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'peygir'@'localhost' IDENTIFIED BY 'یک-رمز-قوی';
GRANT ALL PRIVILEGES ON peygir.* TO 'peygir'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

`utf8mb4` مهم است: کدگذاری پیش‌فرض بعضی نصب‌ها متن فارسی را خراب می‌کند.

---

## ۴. گرفتن کد از گیت‌هاب

مخزن خصوصی است، پس سرور یک **کلید فقط‌خواندنی** می‌خواهد:

```bash
mkdir -p /var/www/peygir && chown peygir:peygir /var/www/peygir
su - peygir

ssh-keygen -t ed25519 -C "peygir-server" -N "" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```

خروجی را کپی کنید. در گیت‌هاب: مخزن ← **Settings** ← **Deploy keys** ←
**Add deploy key**. یک اسم بدهید و متن را بچسبانید. تیک «Allow write access» را
**نزنید**.

بعد، هنوز با کاربر `peygir`:

```bash
git clone -b claude/smart-task-manager-system-duqfjv \
    git@github.com:Mehrabshahidi7777/claud.git /var/www/peygir
cd /var/www/peygir
```

بار اول می‌پرسد `Are you sure you want to continue connecting`؛ بنویسید `yes`.

> اگر شاخه را در `main` ادغام کردید، `-b main` بنویسید. اسکریپت به‌روزرسانی همیشه
> همان شاخه‌ای را می‌کشد که سرور رویش است.

---

## ۵. فایل ‎.env

```bash
cp .env.example .env
nano .env
```

این‌ها را عوض کنید. بقیه را فعلاً دست نزنید:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://peygir.ir

LOG_STACK=daily          # هر روز یک فایل، ۱۴ روز نگه‌داری

DB_DATABASE=peygir
DB_USERNAME=peygir
DB_PASSWORD=همان-رمز-بخش-۳

SESSION_SECURE_COOKIE=true

MAIL_FROM_ADDRESS=report@peygir.ir
```

> ⚠️ `APP_DEBUG=false` حیاتی است. با `true` هر خطا رمز دیتابیس را به بازدیدکننده نشان
> می‌دهد.
>
> `LOG_LEVEL` را فعلاً روی `debug` بگذارید تا کد ورود در لاگ دیده شود (بخش ۹). بعد از
> روشن کردن آموت آن را `warning` کنید.

`SMS_DRIVER=log` و `PAYMENT_GATEWAY=fake` و `AI_PROVIDER=null` بمانند تا بخش‌های
۱۰ تا ۱۲.

---

## ۶. اولین نصب

هنوز با کاربر `peygir` و داخل `/var/www/peygir`:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan key:generate --force

bash docs/deploy/deploy.sh       # ساخت CSS/JS، مایگریشن، کش
php artisan holidays:seed        # تعطیلات رسمی امسال
```

`deploy.sh` در پایان `app:check` را اجرا می‌کند. خط‌های زرد (پیامک لاگ، درگاه
آزمایشی و…) در این مرحله طبیعی‌اند. **خط قرمز** یعنی چیزی درست نیست.

> دموی `DemoSeeder` را روی سرور اصلی اجرا **نکنید**. آن برای نمایش است، نه برای
> مشتری واقعی.

برگردید به root:

```bash
exit
```

---

## ۷. وب‌سرور و SSL

```bash
DOMAIN=peygir.ir        # دامنه‌ی خودتان
cd /var/www/peygir

# PHP
cp docs/deploy/php-fpm.conf /etc/php/8.4/fpm/pool.d/peygir.conf
systemctl restart php8.4-fpm

# nginx
cp docs/deploy/nginx.conf /etc/nginx/sites-available/peygir
sed -i "s/peygir\.ir/$DOMAIN/g" /etc/nginx/sites-available/peygir
ln -s /etc/nginx/sites-available/peygir /etc/nginx/sites-enabled/peygir
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

حالا `http://` دامنه‌تان باید صفحه‌ی ورود را نشان بدهد.

**SSL** (رایگان، Let's Encrypt):

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d $DOMAIN -d www.$DOMAIN --redirect -m you@example.com --agree-tos -n
```

`certbot` خودش بلوک `443` و انتقال `http` به `https` را اضافه می‌کند و تمدید را هم
زمان‌بندی می‌کند. بدون `https` درگاه بانک کال‌بک نمی‌دهد.

> گواهی ۹۰ روزه است و تمدید از ۳۰ روز مانده شروع می‌شود. تأیید Let's Encrypt از
> خارج انجام می‌شود، پس اگر اینترنت بین‌الملل موقع تمدید قطع باشد تمدید شکست
> می‌خورد و خودش روزهای بعد دوباره تلاش می‌کند. یک قطعی چندروزه مشکلی نمی‌سازد.

---

## ۸. صف، کرون و بکاپ

```bash
# صف: پاسخ‌های پیامکی («انجام شد»، «۲ روز دیگر») را پردازش می‌کند
cp /var/www/peygir/docs/deploy/supervisor.conf /etc/supervisor/conf.d/peygir.conf
supervisorctl reread && supervisorctl update
supervisorctl status          # باید RUNNING باشد
```

**کرون** قلب موتور است: بدون آن هیچ پیگیری و هیچ گزارشی فرستاده نمی‌شود.

```bash
crontab -u peygir -e
```

این دو خط را اضافه کنید:

```cron
* * * * * cd /var/www/peygir && php artisan schedule:run >> /dev/null 2>&1
30 3 * * * bash /var/www/peygir/docs/deploy/backup.sh >> /home/peygir/backup.log 2>&1
```

زمان‌های داخل برنامه به **وقت تهران** است (پیگیری مطالبات ۸:۳۰، کارهای دوره‌ای
۷:۳۰ و…). ساعت خود سرور را عوض نکنید؛ دیتابیس عمداً روی UTC است.

**رمز بکاپ** را یک‌بار در فایلی بگذارید که فقط کاربر `peygir` می‌خواند:

```bash
su - peygir
cat > ~/.my.cnf <<'EOF'
[client]
host=127.0.0.1
user=peygir
password=همان-رمز-بخش-۳
EOF
chmod 600 ~/.my.cnf

bash /var/www/peygir/docs/deploy/backup.sh && ls ~/backups     # یک فایل .sql.gz
exit
```

بکاپ‌ها ۱۴ روز در `/home/peygir/backups` می‌مانند. **هفته‌ای یک‌بار یک نسخه را روی
کامپیوتر خودتان بیاورید.** بکاپی که روی همان دیسک است، بکاپ نیست:

```powershell
scp peygir@SERVER_IP:backups/*.sql.gz .
```

یک نسخه از فایل `.env` را هم جای امنی نگه دارید. بدون `APP_KEY` آن، نشست‌ها و
داده‌های رمزشده برنمی‌گردند.

---

## ۹. اولین ورود

`https://peygir.ir` را باز کنید و **شماره‌ی خودتان** را بزنید. تا آموت روشن نشده کد
به‌جای پیامک در لاگ نوشته می‌شود:

```bash
cd /var/www/peygir && grep "SMS (log driver)" $(ls -t storage/logs/laravel-*.log | head -1) | tail -1
```

کد را وارد کنید. فضای کاری و نوعش (شرکتی، خانوادگی یا دوستانه) را می‌سازید و
مالک آن می‌شوید.

---

## ۱۰. روشن کردن پیامک آموت

۱. در پنل آموت متن پترن `otp` را دقیقاً همان‌طور که در `config/sms.php` آمده ثبت
کنید. بعد از تأیید، کد پترن را بگیرید. بقیه‌ی پترن‌ها را هم هر وقت تأیید شدند اضافه
کنید. پترن ثبت‌نشده فقط همان یک پیام را نمی‌فرستد و بقیه کار می‌کنند.

۲. رمز وب‌هوک را بسازید:

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

۳. در `.env`:

```ini
SMS_DRIVER=amoot
AMOOT_TOKEN=توکن-پنل
AMOOT_LINE_NUMBER=شماره-خط-اختصاصی
AMOOT_INBOUND_SECRET=رمزی-که-ساختید
AMOOT_PATTERN_OTP=کد-پترن
LOG_LEVEL=warning
```

۴. در پنل آموت، آدرس دریافت پیامک ورودی را این بگذارید (به‌جای `x7k2` هر رشته‌ی
تصادفی) و رمز را در هدر `X-Webhook-Secret` بدهید:

```
https://peygir.ir/api/webhooks/sms/amoot/x7k2
```

۵. اعمال و بررسی:

```bash
su - peygir -c "cd /var/www/peygir && php artisan optimize && php artisan queue:restart && php artisan app:check --credit"
```

> ⚠️ **بعد از هر تغییر در `.env`** باید `php artisan optimize` بزنید. تنظیمات کش
> شده‌اند و بدون آن تغییر دیده نمی‌شود. این شایع‌ترین «چرا کار نمی‌کند» است.

---

## ۱۱. روشن کردن درگاه سامان

وقتی ترمینال آماده شد:

- در پنل سامان: آدرس بازگشت `https://peygir.ir/billing/callback` و **آی‌پی سرور**
  ثبت شود.
- در `.env`:

  ```ini
  PAYMENT_GATEWAY=sep
  SEP_TERMINAL_ID=شماره-ترمینال
  ```

- `php artisan optimize` (با کاربر `peygir`).
- **یک پرداخت واقعی کوچک** بزنید و ببینید فاکتور «پرداخت‌شده» می‌شود.

---

## ۱۲. هوش مصنوعی (اختیاری)

فقط اگر سرور دست‌کم ۸ گیگ رم دارد:

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull qwen2.5:7b-instruct
```

> **اگر دانلود مدل از سرور باز نشد:** شما روی کامپیوتر خودتان اولاما و مدل را دارید.
> همان را منتقل کنید. در PowerShell:
>
> ```powershell
> scp -r $env:USERPROFILE\.ollama\models root@SERVER_IP:/tmp/ollama-models
> ```
>
> روی سرور:
>
> ```bash
> cp -r /tmp/ollama-models/* /usr/share/ollama/.ollama/models/
> chown -R ollama:ollama /usr/share/ollama/.ollama
> systemctl restart ollama && ollama list
> ```
>
> اگر خود نصب‌کننده هم باز نشد، فایل لینوکس را از صفحه‌ی Releases مخزن
> `ollama/ollama` در گیت‌هاب روی کامپیوترتان بگیرید و با `scp` بفرستید.

در `.env`، روی سرور مجازی **هر دو مدل را ۷B بگذارید.** مدل ۱۴B روی پردازنده خیلی کند
است:

```ini
AI_PROVIDER=ollama
OLLAMA_MODEL_EXTRACTION=qwen2.5:7b-instruct
OLLAMA_MODEL_WRITING=qwen2.5:7b-instruct
```

و `php artisan optimize`.

---

## ۱۳. به‌روزرسانی‌های بعدی

هر بار که کد تازه به گیت‌هاب رفت:

```bash
su - peygir -c "cd /var/www/peygir && bash docs/deploy/deploy.sh"
```

کد را می‌کشد، وابستگی‌ها و CSS/JS را می‌سازد، مایگریشن می‌زند، کش را تازه می‌کند و
صف را ری‌استارت می‌کند. سایت فقط چند ثانیه‌ی مایگریشن پایین است.

**اگر سرور به npm دسترسی ندارد:** روی کامپیوتر خودتان، در پوشه‌ی پروژه:

```powershell
npm run build
scp -r public\build peygir@SERVER_IP:/var/www/peygir/public/
```

و روی سرور:

```bash
su - peygir -c "cd /var/www/peygir && SKIP_ASSETS=1 bash docs/deploy/deploy.sh"
```

---

## ۱۴. وقتی اینترنت بین‌الملل قطع است

همه‌چیز این سامانه داخلی است، پس سرور ایرانی در قطعی سرپا می‌ماند:

| | در قطعی بین‌الملل |
| --- | --- |
| سایت و صفحه‌ها | ✅ کار می‌کند. فونت هم روی خود سرور است. |
| پیامک آموت | ✅ داخلی |
| درگاه سامان | ✅ شاپرک داخلی است |
| هوش مصنوعی | ✅ اولاما روی خود سرور است |
| پیگیری‌ها و گزارش هفتگی | ✅ |
| به‌روزرسانی از گیت‌هاب | ❌ تا وصل شدن صبر کنید |
| تمدید SSL | ⏸ خودش بعداً دوباره تلاش می‌کند |
| ایمیل به جیمیل | ⚠️ ممکن است نرسد. گزارش هفتگی پیامکی هم می‌رود. |

این جمله در فروش ارزش دارد: **«ابزار خارجی روز قطعی کار نمی‌کند؛ این کار می‌کند.»**

---

## عیب‌یابی

| نشانه | علت معمول | راه |
| --- | --- | --- |
| **502 Bad Gateway** | PHP-FPM بالا نیست یا فایل استخر کپی نشده | `systemctl status php8.4-fpm` و `ls /run/php/peygir.sock` |
| **500 Server Error** | خطای برنامه | `tail -50 $(ls -t storage/logs/laravel-*.log \| head -1)` داخل `/var/www/peygir` |
| صفحه بدون ظاهر است | CSS ساخته نشده | `ls /var/www/peygir/public/build/manifest.json`؛ دوباره `deploy.sh` |
| تغییر `.env` اثر نکرد | کش تنظیمات | `php artisan optimize` |
| پیامک نمی‌رود | پترن، توکن یا اعتبار | `php artisan app:check --credit` |
| پاسخ پیامکی اثر ندارد | صف خاموش است | `supervisorctl status` و `tail /var/log/peygir-queue.log` |
| پیگیری‌ها نمی‌روند | کرون نیست | `crontab -u peygir -l` و `php artisan schedule:list` |
| ثبت سریع با ۵۰۴ قطع می‌شود | مدل خیلی کند است | مدل کوچک‌تر، یا `AI_PROVIDER=null` |
| `Permission denied` در `storage` | فایلی با root ساخته شده | `chown -R peygir:peygir /var/www/peygir` |

> قاعده‌ی طلایی: هر دستور `php artisan` را با کاربر `peygir` بزنید، **نه root**.
> فایلی که root در `storage` بسازد، سایت نمی‌تواند رویش بنویسد.
