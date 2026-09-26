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
- [پیوست: همه‌ی مقادیر ‎.env یکجا](#پیوست-همهی-مقادیر-env-یکجا)
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

### اگر سرور به گیت‌هاب وصل نشد

کد را از کامپیوتر خودتان بفرستید. در **Git Bash**، داخل پوشه‌ی پروژه:

```bash
git archive --format=tar.gz -o peygir.tar.gz HEAD
scp peygir.tar.gz root@SERVER_IP:/tmp/
```

روی سرور (با root):

```bash
mkdir -p /var/www/peygir
tar -xzf /tmp/peygir.tar.gz -C /var/www/peygir
chown -R peygir:peygir /var/www/peygir
```

در این حالت هر جا `deploy.sh` را اجرا می‌کنید، `SKIP_PULL=1` جلویش بگذارید (بخش ۶
و ۱۳)؛ برای به‌روزرسانی بعدی همین سه دستور را تکرار کنید. فایل `.env` و پوشه‌ی
`storage` سرور دست نمی‌خورند، چون در آرشیو نیستند.

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

PLATFORM_ADMIN_PHONES=09134451502   # شماره‌ی شما برای پنل مدیریت کل

MAIL_FROM_ADDRESS=report@peygir.ir
```

> ⚠️ `APP_DEBUG=false` حیاتی است. با `true` هر خطا رمز دیتابیس را به بازدیدکننده نشان
> می‌دهد.
>
> `LOG_LEVEL` را فعلاً روی `debug` بگذارید تا کد ورود در لاگ دیده شود (بخش ۹). بعد از
> روشن کردن آموت آن را `warning` کنید.

`SMS_DRIVER=log` و `PAYMENT_GATEWAY=fake` و `AI_PROVIDER=null` بمانند تا بخش‌های
۱۰ تا ۱۲.

> **از `.env` کامپیوتر خودتان چه چیزی را بیاورید؟** فقط مقادیری که از بیرون گرفته‌اید:
> `AMOOT_BASE_URL`، `AMOOT_TOKEN`، `AMOOT_LINE_NUMBER`، کد پترن‌ها، `SEP_TERMINAL_ID`
> و `PLATFORM_ADMIN_PHONES`. **کل فایل را کپی نکنید:** `APP_KEY`، `APP_ENV`،
> `APP_DEBUG` و تنظیمات دیتابیس روی سرور فرق دارند.

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

> ⚠️ **داده‌ی نمونه روی سرور نمی‌آید.** دیتابیس سرور **خالی** شروع می‌شود؛ شرکت‌ها،
> تسک‌ها و شماره‌های ساختگی (`0912111000…`) فقط روی کامپیوتر خودتان هستند و چیزی
> برای پاک کردن اینجا نیست. فقط **`DemoSeeder` را روی سرور اجرا نکنید.** آن شماره‌ها
> وجود خارجی ندارند و پیامک واقعی به آن‌ها نمی‌رسد، ولی آمار پنل مدیریت را با
> مشتری‌های الکی پر می‌کنند.
>
> اگر اشتباهی اجرا شد و **هنوز مشتری واقعی ندارید**، این دو دستور دیتابیس را از صفر
> می‌سازند (همه‌چیز پاک می‌شود):
>
> ```bash
> php artisan migrate:fresh --force
> php artisan holidays:seed
> ```
>
> بعد از آمدن اولین مشتری واقعی، `migrate:fresh` را **هرگز** روی سرور نزنید.

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

`https://peygir.ir` را باز کنید و **شماره‌ی واقعی خودتان** را بزنید (همان
`PLATFORM_ADMIN_PHONES`). تا آموت روشن نشده، کد به‌جای پیامک در لاگ نوشته می‌شود:

```bash
cd /var/www/peygir && grep "SMS (log driver)" $(ls -t storage/logs/laravel-*.log | head -1) | tail -1
```

کد را وارد کنید؛ مستقیم به **پنل مدیریت کل** (`https://peygir.ir/admin`) می‌روید.
آنجا همه‌ی مشتری‌ها، پرداخت‌ها و پیامک‌ها را می‌بینید. اول کار خالی است؛ هر مشتری
که ثبت‌نام کند اینجا می‌آید.

- مدیر کل لازم نیست فضای کاری داشته باشد. هر صفحه‌ای از سامانه را باز کنید، به همین
  پنل برمی‌گردید.
- اگر می‌خواهید خودتان هم از پیگیر استفاده کنید (مثلاً برای کارهای شرکت خودتان)،
  بالای پنل **«ساختن فضای کاری خودم»** را بزنید. بعد از آن دکمه‌ی زرد **«مدیریت کل»**
  در بالای سامانه شما را به پنل برمی‌گرداند.
- **مشتری‌ها** خودشان با شماره‌ی خودشان وارد می‌شوند، اسم و شرکتشان را می‌دهند و
  ۱۵ روز آزمایشی رایگان می‌گیرند؛ هر شماره فقط یک بار.

> **برای نمایش به مشتری (دمو):** همان نسخه‌ی کامپیوتر خودتان با داده‌ی نمونه
> بهترین است؛ همه‌چیز پر و آماده است و روی سرور واقعی هم اثری ندارد.

---

## ۱۰. روشن کردن پیامک آموت

### الف) ثبت پترن‌ها در پنل

۱۲ پترن است. متن هرکدام را **عیناً** از جعبه‌اش کپی کنید؛ هر خط جدید در جعبه، در
پنل هم Enter است. بعد از تأیید، کدی را که پنل می‌دهد جلوی متغیر `.env` همان پترن
بگذارید.

> ⚠️ **سه قاعده:**
>
> - متغیرهای آموت با **درصد** نوشته می‌شوند: `%name%`، نه `{name}`.
> - **ترتیب متغیرها** باید همان باشد که نوشته شده؛ مقدارها به ترتیب فرستاده می‌شوند.
> - آموت پترن بی‌متغیر را ثبت نمی‌کند؛ همه‌ی این ۱۲ پترن دست‌کم یک متغیر دارند.
>
> اگر پنل کلمه‌ای را اصلاح خواست، کلمه را عوض کنید، متغیرها و ترتیبشان را نه؛ و
> متن نهایی را در `config/sms.php` هم بگذارید تا طول پیامک درست حساب شود.

فقط **`otp`** برای شروع لازم است. پترن ثبت‌نشده فقط همان یک پیام را نمی‌فرستد و
بقیه‌ی سامانه کار می‌کند.

#### مرحله‌ی ۱ — لازم برای ورود

**۱. کد ورود** → `AMOOT_PATTERN_OTP` · متغیرها به ترتیب: ۱. `code`

```text
کد ورود: %code%
تا ۲ دقیقه معتبر است
```

#### مرحله‌ی ۲ — موتور پیگیری

**۲. پیگیری سررسید** → `AMOOT_PATTERN_CHASE` · متغیرها به ترتیب: ۱. `name`، ۲. `title`

```text
%name%، سررسید: %title%
۱=انجام شد ۲=تأخیر
همین پیامک را پاسخ دهید
```

**۳. درخواست تاریخ جدید** → `AMOOT_PATTERN_DEFER_ASK` · متغیرها به ترتیب: ۱. `name`، ۲. `example`

```text
%name%، تاریخ جدید را بفرستید
مثال: %example%
```

**۴. تأیید انجام** → `AMOOT_PATTERN_CONFIRM_DONE` · متغیرها به ترتیب: ۱. `name`

```text
ثبت شد. ممنون %name%
```

**۵. تأیید تعویق** → `AMOOT_PATTERN_CONFIRM_DEFER` · متغیرها به ترتیب: ۱. `date`

```text
تاریخ جدید ثبت شد: %date%
```

**۶. پاسخ نامفهوم** → `AMOOT_PATTERN_UNKNOWN` · متغیرها به ترتیب: ۱. `name`

```text
%name%، متوجه نشدم. ۱=انجام شد ۲=تأخیر
```

**۷. تشدید به مدیر** → `AMOOT_PATTERN_ESCALATE` · متغیرها به ترتیب: ۱. `title`، ۲. `name`، ۳. `hours`

```text
%title%
مسئول: %name% - بدون پاسخ
تأخیر: %hours% ساعت
```

#### مرحله‌ی ۳ — هر وقت رسیدید

**۸. خوش‌آمد عضو جدید** → `AMOOT_PATTERN_WELCOME` · متغیرها به ترتیب: ۱. `name`، ۲. `workspace`

```text
%name% عزیز، به %workspace% اضافه شدید
```

**۹. گزارش هفتگی** → `AMOOT_PATTERN_WEEKLY_REPORT` · متغیرها به ترتیب: ۱. `rate`، ۲. `overdue`

```text
گزارش هفته آماده است
تکمیل به‌موقع: %rate%٪
عقب‌افتاده: %overdue%
```

**۱۰. یادآوری تمدید** → `AMOOT_PATTERN_SUBSCRIPTION_EXPIRING` · متغیرها به ترتیب: ۱. `plan`، ۲. `days`

```text
اشتراک %plan% تا %days% روز دیگر تمام می‌شود
برای تمدید وارد پنل شوید
```

**۱۱. درخواست تأییدیه** → `AMOOT_PATTERN_APPROVAL_REQUEST` · متغیرها به ترتیب: ۱. `type`، ۲. `name`، ۳. `title`

```text
درخواست %type% از %name%
%title%
منتظر تأیید شماست
```

**۱۲. نتیجه تأییدیه** → `AMOOT_PATTERN_APPROVAL_DECISION` · متغیرها به ترتیب: ۱. `result`، ۲. `title`

```text
درخواست شما %result% شد
%title%
```

`٪` در گزارش هفتگی علامت درصد فارسی و بخشی از متن است، نه متغیر.

### ب) رمز وب‌هوک

پیامک‌هایی که کارمندها در جواب می‌فرستند («۱»، «۲»، تاریخ) از این راه به سامانه
می‌رسند.

```bash
php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
```

در پنل آموت، آدرس دریافت پیامک ورودی (به‌جای `x7k2` هر رشته‌ی تصادفی):

```
https://peygir.ir/api/webhooks/sms/amoot/x7k2
```

و رمز را در هدر `X-Webhook-Secret` بدهید. **اگر پنل فقط آدرس می‌گیرد و هدر ندارد،**
رمز را ته آدرس بگذارید:

```
https://peygir.ir/api/webhooks/sms/amoot/x7k2?secret=رمزی-که-ساختید
```

### ج) مقادیر `.env`

```ini
SMS_DRIVER=amoot
AMOOT_TOKEN=توکن-API-از-پنل
AMOOT_LINE_NUMBER=شماره-خط-دقیقاً-مثل-پنل
AMOOT_INBOUND_SECRET=رمزی-که-ساختید
AMOOT_PATTERN_OTP=کد-پترن
# بقیه‌ی AMOOT_PATTERN_... هم هر وقت تأیید شدند
LOG_LEVEL=warning
```

**شماره‌ی خط:** دقیقاً همان‌طور که در پنل آموت نوشته شده، بدون فاصله و بدون `+`؛
مثلاً اگر با `98` شروع می‌شود، با همان `98` بنویسید. اگر پنل برای ارسال پترنی
شماره‌ی خط نمی‌خواهد، خالی بگذارید؛ آن‌وقت اصلاً فرستاده نمی‌شود.

**آدرس ارسال پترن، مطابق [مستندات رسمی آموت](https://github.com/AmootSoft/AmootSMS):**

| حالت | آدرس |
| --- | --- |
| **با خط اختصاصی** (`AMOOT_LINE_NUMBER` پر است) | `https://portal.amootsms.com/rest/SendWithPatternOWN` |
| بدون خط اختصاصی (خط عمومی پترن آموت) | `https://portal.amootsms.com/rest/SendWithPattern` |
| استعلام اعتبار | `https://portal.amootsms.com/rest/AccountStatus` |

سامانه **خودش** بر اساس پر یا خالی بودن `AMOOT_LINE_NUMBER` درست را انتخاب می‌کند؛
شما فقط شماره‌ی خط را بگذارید. `AMOOT_BASE_URL` همان
`https://portal.amootsms.com/rest` می‌ماند.

فقط اگر روزی آموت آدرس را عوض کرد، آدرس کامل تازه را در `.env` بگذارید؛ کد لازم
نیست عوض شود:

```ini
AMOOT_SEND_PATTERN_URL=https://آدرس-کامل-تازه
```

### د) اعمال و بررسی

```bash
su - peygir -c "cd /var/www/peygir && php artisan optimize && php artisan queue:restart && php artisan app:check --credit"
```

`app:check` نشان می‌دهد کدام پترن‌ها ثبت شده‌اند و اعتبار پنل چقدر است. بعد با
شماره‌ی خودتان خارج و دوباره وارد شوید: این بار کد باید **با پیامک واقعی** برسد.

> ⚠️ **اولین ارسال واقعی:** قالب درخواست مطابق مستندات رسمی آموت است، ولی شکل دقیق
> پاسخ پنل در مستندات نیامده. اگر پیامک نرفت، جواب پنل در `storage/logs` و در صفحه‌ی
> «پیامک‌ها»ی پنل مدیریت کل ثبت می‌شود؛ همان را برای برنامه‌نویس بفرستید.
>
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

## پیوست: همه‌ی مقادیر `.env` یکجا

هرچه از بیرون می‌گیرید و کجا می‌رود:

| از کجا | چه می‌گیرید | کجای `.env` |
| --- | --- | --- |
| **شما** | شماره‌ی موبایل مدیر کل | `PLATFORM_ADMIN_PHONES=09134451502` |
| **دامنه** | آدرس سایت | `APP_URL=https://peygir.ir` |
| **MySQL** (بخش ۳) | رمز دیتابیس | `DB_PASSWORD` |
| **پنل آموت** | توکن API | `AMOOT_TOKEN` |
| | شماره‌ی خط اختصاصی، دقیقاً مثل پنل (مثلاً با `98`) | `AMOOT_LINE_NUMBER` |
| | آدرس ارسال پترن: **لازم نیست**، خودکار `SendWithPatternOWN` | `AMOOT_SEND_PATTERN_URL` (خالی) |
| | کد ۱۲ پترن (بخش ۱۰) | `AMOOT_PATTERN_OTP` … `AMOOT_PATTERN_APPROVAL_DECISION` |
| **خودتان می‌سازید** | رمز وب‌هوک | `AMOOT_INBOUND_SECRET` |
| **بانک سامان** | شماره‌ی ترمینال | `SEP_TERMINAL_ID` |
| **اولاما** (بخش ۱۲) | نام مدل‌ها | `OLLAMA_MODEL_EXTRACTION`، `OLLAMA_MODEL_WRITING` |

و کلیدهایی که فقط **روشن** می‌کنید، هرکدام وقتی آماده شد:

| کلید | تا آماده نشده | وقتی آماده شد |
| --- | --- | --- |
| `SMS_DRIVER` | `log` (کد در لاگ) | `amoot` |
| `PAYMENT_GATEWAY` | `fake` (پولی جابه‌جا نمی‌شود) | `sep` |
| `AI_PROVIDER` | `null` (فرم دستی) | `ollama` |
| `LOG_LEVEL` | `debug` | `warning` |

در پنل‌ها هم باید این‌ها ثبت شود:

| کجا | چه چیزی |
| --- | --- |
| پنل آموت | ۱۲ پترن، و آدرس وب‌هوک ورودی با رمز (بخش ۱۰) |
| پنل سامان | آدرس بازگشت `https://peygir.ir/billing/callback` و **آی‌پی سرور** |
| DNS دامنه | رکورد A برای `@` و `www` به آی‌پی سرور |

بعد از هر تغییر: `php artisan optimize` و `php artisan app:check`.

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
