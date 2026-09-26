# راه‌اندازی روی کامپیوتر خودتان (ویندوز + Git Bash)

حدود **نیم ساعت**. آخرش سامانه با دیتای نمونه روی `http://127.0.0.1:8000` بالا است:
سه فضای کاری (شرکتی، خانوادگی، دوستانه)، کارها، قراردادها، مالی و گزارش.

روی کامپیوتر خودتان پیامک واقعی نمی‌رود و پولی هم جابه‌جا نمی‌شود. هر پیامک در
فایل لاگ نوشته می‌شود و کد ورود را از همان‌جا برمی‌دارید.

همه‌ی دستورها را در **Git Bash** بزنید، نه CMD یا PowerShell.

> برای سرور مجازی راهنمای جدا هست: [`docs/deploy/README.md`](../deploy/README.md)

---

## ۰. پیش‌نیازها

```bash
php -v
composer -V
node -v
git --version
```

| | لازم |
| --- | --- |
| PHP | **8.4** یا بالاتر |
| Composer | 2 |
| Node | 20.19 یا بالاتر (22 بهتر) |

### اگر `php -v` کمتر از 8.4 بود

XAMPP معمولاً PHP 8.2 دارد و این پروژه 8.4 می‌خواهد. XAMPP را دست نزنید؛ یک PHP
8.4 کنارش بگذارید:

۱. از **windows.php.net/download** بخش PHP 8.4 فایل **VS17 x64 Thread Safe** (Zip)
را بگیرید و در `C:\php84` باز کنید، طوری که `C:\php84\php.exe` وجود داشته باشد.

۲. در Git Bash:

```bash
cd /c/php84
cp php.ini-development php.ini

# روشن کردن افزونه‌هایی که پروژه لازم دارد
sed -i -E 's/^;extension_dir = "ext"(\r?)$/extension_dir = "ext"\1/; s/^;extension=(curl|fileinfo|intl|mbstring|openssl|pdo_mysql|pdo_sqlite|sqlite3|zip)(\r?)$/extension=\1\2/' php.ini

# سریع‌تر شدن PHP (OPcache) و وقت بیشتر برای کامپیوترهای قدیمی‌تر
sed -i -E 's/^max_execution_time = 30(\r?)$/max_execution_time = 120\1/; s/^;zend_extension=opcache(\r?)$/zend_extension=opcache\1/; s/^;opcache\.enable=1(\r?)$/opcache.enable=1\1/' php.ini

# این PHP را جلوتر از PHP زمپ بگذار
echo 'export PATH="/c/php84:$PATH"' >> ~/.bashrc
source ~/.bashrc

php -v        # حالا باید 8.4 باشد
```

اگر خطای `VCRUNTIME140.dll` دیدید، **Microsoft Visual C++ Redistributable (x64)**
را از سایت مایکروسافت نصب کنید.

---

## ۱. گرفتن کد از گیت‌هاب

```bash
cd ~
git clone -b claude/smart-task-manager-system-duqfjv https://github.com/Mehrabshahidi7777/claud.git peygir
cd peygir
```

مخزن خصوصی است. بار اول یک پنجره‌ی ورود گیت‌هاب باز می‌شود. وارد حساب
گیت‌هابتان شوید تا دانلود ادامه پیدا کند. این فقط یک‌بار است.

> اگر به‌جای پنجره، در خود ترمینال رمز خواست: رمز حسابتان کار نمی‌کند و یک
> **توکن** لازم است. گیت‌هاب ← Settings ← Developer settings ← Personal access
> tokens ← Generate. توکن را به‌جای رمز بچسبانید.

---

## ۲. نصب وابستگی‌ها

```bash
composer install
cp .env.example .env
php artisan key:generate
```

---

## ۳. دیتابیس

برای آزمایش روی کامپیوتر خودتان **SQLite** ساده‌ترین است: یک فایل است و نصب
نمی‌خواهد.

```bash
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/; s/^DB_DATABASE=/# DB_DATABASE=/' .env
touch database/database.sqlite

php artisan migrate
php artisan db:seed --class=DemoSeeder      # دیتای نمونه
```

> روی سرور واقعی MySQL 8 می‌گذاریم (راهنمای سرور). MySQL زمپ در واقع MariaDB است
> و برای آزمایش لازم نیست.

---

## ۴. ساختن ظاهر

```bash
npm install
npm run build
```

---

## ۵. اجرا

```bash
php artisan serve
```

مرورگر: **http://127.0.0.1:8000**

این پنجره باید باز بماند. **یک Git Bash دیگر** باز کنید برای کد ورود:

```bash
cd ~/peygir
grep "SMS (log driver)" storage/logs/laravel.log | tail -1
```

عدد جلوی `"code"` کد ورود است.

### حساب‌های نمونه

| شماره | کیست | چه چیزی را نشان می‌دهد |
| --- | --- | --- |
| `09121110001` | مهراب شهیدی، مالک | همه‌چیز. از بالای صفحه بین «تأسیسات پارس»، «خانه» و «سفر شمال» جابه‌جا شوید |
| `09121110002` | سعید کریمی، مدیر عملیات (نقش «مدیر») | همه‌ی کارها و جلسات، **بدون** صورتحساب |
| `09121110009` | سمیه رحیمی، حسابدار | مالی و مطالبات، **بدون** صفحه‌ی اعضا |
| `09121110010` | زهرا کاظمی، منابع انسانی | اعضا و درخواست‌ها، **بدون** مالی |
| `09121110003` | رضا مرادی، تکنسین | فقط کارهای خودش |
| `09134451502` | **شما، مدیر کل** | پنل `/admin`: همه‌ی مشتری‌ها، پرداخت‌ها و پیامک‌ها |

> **این شماره‌ها ساختگی‌اند و لازم نیست واقعی باشند.** روی کامپیوتر خودتان هیچ پیامکی
> واقعاً نمی‌رود؛ کد ورود فقط در لاگ نوشته می‌شود و از همان‌جا برمی‌دارید. این
> داده‌ها فقط روی کامپیوتر خودتان‌اند و **روی سرور ساخته نمی‌شوند**؛ آنجا دیتابیس
> خالی شروع می‌شود و فقط شماره‌های واقعی کار می‌کنند.

شماره‌ی مدیر کل (`09134451502`) فضای کاری ندارد و مستقیم به پنل `/admin` می‌رود؛ هر
صفحه‌ی دیگری را هم باز کند به همان‌جا برمی‌گردد.

برای ورود با شماره‌ی بعدی اول «خروج» بزنید. برای یک شماره هر دقیقه فقط یک کد صادر
می‌شود.

---

## ۶. موتور پیگیری (اختیاری)

برای اینکه پیگیری‌ها و یادآوری‌ها واقعاً راه بیفتند، دو پنجره‌ی Git Bash دیگر:

```bash
cd ~/peygir && php artisan schedule:work     # هر ۵ دقیقه پیگیری‌های سررسید را می‌فرستد
```

```bash
cd ~/peygir && php artisan queue:work        # پاسخ‌های پیامکی را پردازش می‌کند
```

پیامک‌ها واقعی نیستند و در `storage/logs/laravel.log` نوشته می‌شوند. متن هرکدام را
آنجا می‌بینید.

---

## ۷. هوش مصنوعی با اولاما

```bash
ollama pull qwen2.5:7b-instruct
ollama pull qwen2.5:14b-instruct      # فقط اگر ۱۶ گیگ رم یا بیشتر دارید
```

در فایل `.env` (با VS Code یا Notepad باز کنید):

```ini
AI_PROVIDER=ollama
OLLAMA_MODEL_EXTRACTION=qwen2.5:7b-instruct
OLLAMA_MODEL_WRITING=qwen2.5:14b-instruct    # با رم کم: همان 7b
```

بعد:

```bash
php artisan config:clear
php artisan app:check          # بخش «هوش مصنوعی» باید سبز شود
```

آزمایش: صفحه‌ی کارها ← «ثبت سریع با متن آزاد» ← بنویسید «فردا ساعت ۱۰ با آقای
رضایی جلسه، گزارش فروش را هم تا پنجشنبه آماده کن» ← «تبدیل به کار».

---

## ۸. گرفتن نسخه‌های بعدی

هر وقت کد تازه روی گیت‌هاب رفت:

```bash
cd ~/peygir
git pull
composer install
npm install && npm run build
php artisan migrate
```

---

## عیب‌یابی

| پیام | راه |
| --- | --- |
| `php: command not found` یا نسخه‌ی 8.2 | بخش ۰ |
| `MissingAppKeyException` | `APP_KEY` در `.env` خالی است: `php artisan key:generate` و بعد `php artisan optimize:clear` |
| `Maximum execution time of 30 seconds exceeded` | معمولاً پشت یک خطای دیگر است؛ اول آن را درست کنید. برای سرعت، دستور OPcache بخش ۰ را بزنید و `php artisan serve` را دوباره اجرا کنید |
| `could not find driver` | افزونه‌ی `pdo_sqlite` روشن نشده؛ دستور `sed` بخش ۰ را دوباره بزنید |
| `Vite manifest not found` | `npm run build` |
| «کد تازه فرستاده شد…» | یک دقیقه صبر کنید، یا `php artisan cache:clear` |
| صفحه‌ی **419** | صفحه را رفرش کنید و دوباره بفرستید |
| پورت 8000 اشغال است | `php artisan serve --port=8080` و آدرس `http://127.0.0.1:8080` |
| تغییر `.env` اثر نکرد | `php artisan config:clear` |
| همه‌چیز به هم ریخت، می‌خواهم از اول | `rm database/database.sqlite && touch database/database.sqlite && php artisan migrate && php artisan db:seed --class=DemoSeeder` |
