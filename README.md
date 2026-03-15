<div align="center">

# ✈ fly-CMS

**Самописна PHP CMS без фреймворків — швидка, безпечна, повністю під вашим контролем**

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white)](https://sqlite.org)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.7.0--AI-2E5FA3?style=flat-square)](https://github.com/fly380/fly-cms/releases)

[Встановлення](#-встановлення) · [Архітектура](#-архітектура) · [Безпека](#-безпека) · [Оновлення](#-оновлення)

</div>

---

## Що це таке

fly-CMS — повнофункціональна система управління контентом, написана повністю вручну на PHP **без зовнішніх фреймворків**. Розроблена для малих і середніх сайтів, де важлива повна контрольованість, мінімум залежностей і зручна адмінка українською мовою.

```
Мова / Runtime    PHP 8.1+, без фреймворку
База даних        SQLite 3 (WAL-режим) або MySQL
Шаблонізатор      Twig (публічна частина)
Адмінка           Bootstrap 5.3 + ванільний JS
AI-інтеграція     Groq API (llama-3.1-8b-instant)
2FA               TOTP (RFC 6238)
Авторизація       PHP sessions + bcrypt + CSRF
Веб-сервери       Apache / IIS / Nginx
```

---

## Можливості адмінки

| Модуль | Опис |
|--------|------|
| 📊 **Dashboard** | Статистика, онлайн-сесії, IP-алерти, нотатки з нагадуваннями |
| 📄 **Сторінки** | Статичні сторінки: slug, чернетки, SEO, власні CSS/JS, видимість за ролями |
| 📝 **Записи / Блог** | TinyMCE, планування публікацій, категорії, теги, ревізії |
| 🖼 **Медіабібліотека** | Завантаження, автотранслітерація назв, хеш, пошук |
| 🎨 **Theme Builder** | 70+ CSS-параметрів: кольори, шрифти, navbar, hero, footer — без коду |
| 🤖 **AI-помічник** | Groq API: генерація тексту прямо в редакторі контенту |
| 👥 **Користувачі** | RBAC: 4 рівні ролей, зміна пароля, видалення |
| 🔐 **2FA** | TOTP (RFC 6238) — Google Authenticator, Authy |
| ✉️ **Запрошення** | Одноразові посилання з роллю, терміном дії, email-доставкою |
| 📧 **SMTP** | Власна реалізація без PHPMailer: SSL/TLS/none, налаштування через UI |
| 🛠 **Підтримка** | Тікет-система: переписка з розробником прямо в CMS |
| 💾 **Бекап** | БД + файли, автобекап за розкладом, зберігання в захищеній директорії |
| 🗄 **MySQL** | Міграція SQLite → MySQL через 4-кроковий wizard без втрати даних |
| 🔄 **Оновлення** | Автооновлення з GitHub Releases: завантаження, розпакування, бекап БД |
| 📁 **Файловий менеджер** | Перегляд і редагування файлів сервера |
| 📋 **Логи** | Журнал дій адміністраторів з IP-алертами |
| 🗃 **phpLiteAdmin** | Вбудований інтерфейс для SQLite з подвійним захистом |

---

## Вимоги

| Компонент | Вимога |
|-----------|--------|
| PHP | **8.1+** (рекомендовано 8.2 / 8.3) |
| Розширення | `pdo_sqlite`, `mbstring`, `json`, `gd`, `zip` |
| MySQL (опц.) | 5.7+ або MariaDB 10.3+ |
| Веб-сервер | Apache 2.4+ / IIS 10+ / Nginx |
| Пам'ять | `memory_limit` мін. 64M, рекомендовано 128M+ |
| Завантаження | `upload_max_filesize` мін. 8M, рекомендовано 50M+ |

```bash
# Перевірка розширень
php -m | grep -E "pdo|sqlite|mysql|json|mbstring|zip|gd"
```

---

## 🚀 Встановлення

### 1. Завантажити файли

```bash
git clone https://github.com/fly380/fly-cms.git
cd fly-cms
composer install --no-dev --optimize-autoloader
```

Або завантажте ZIP з [Releases](https://github.com/fly380/fly-cms/releases) і розпакуйте у webroot.

> Якщо Composer недоступний на хостингу — зберіть `vendor/` локально і завантажте разом з файлами.

### 2. Відкрити інсталятор

```
https://your-domain.com/install.php
```

| Крок | Що відбувається |
|------|-----------------|
| 1. Вимоги | Перевірка PHP, розширень, прав запису |
| 2. База даних | SQLite або MySQL з тестом з'єднання і авто-CREATE |
| 3. Сайт | Назва, опис, демо-контент |
| 4. Адміністратор | Логін, пароль (bcrypt cost 12), відображуване ім'я |
| 5. Встановлення | Директорії, схема БД (16 таблиць), `.env`, lock-файл, самовидалення |

### 3. Налаштувати `.env`

Інсталятор генерує файл автоматично у `../cms_storage/.env` (поза webroot). За потреби відредагуйте:

```env
# База даних
DB_DRIVER=sqlite          # або mysql

# MySQL (тільки якщо DB_DRIVER=mysql)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=flycms
DB_USER=flycms_user
DB_PASS=your_secure_password

# SMTP
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your@gmail.com
SMTP_PASSWORD=xxxx xxxx xxxx xxxx   # App Password для Gmail/UKR.net
SMTP_FROM_NAME=fly-CMS
SMTP_FROM_EMAIL=your@gmail.com
SMTP_ENCRYPTION=tls

# AI (Groq) — необов'язково
GROQ_API_KEY=gsk_...

# phpLiteAdmin
PHPLITEADMIN_PASSWORD=your_secure_password
```

### 4. Налаштувати HTTP Basic Auth для `/admin/`

**Apache** — додайте у `admin/.htaccess`:
```apache
AuthType Basic
AuthName "Admin"
AuthUserFile /home/youruser/.htpasswd
Require valid-user
```

Створити файл паролів:
```bash
htpasswd -c /home/youruser/.htpasswd admin
```

**Nginx:**
```nginx
location /admin/ {
    auth_basic "Admin";
    auth_basic_user_file /etc/nginx/.htpasswd;
}
```

---

## 🏗 Архітектура

### Структура файлів

```
your-site/                          ← webroot (DOCUMENT_ROOT)
│
├── config.php                      ← центральна конфігурація, fly_db()
├── index.php                       ← контролер головної сторінки
├── router.php                      ← front-controller (URL routing)
├── install.php                     ← інсталятор (видаляється після setup)
├── web.config                      ← IIS: HTTPS redirect, URL Rewrite
│
├── admin/                          ← адмінпанель
│   ├── .htaccess                   ← HTTP Basic Auth
│   ├── functions.php               ← bootstrap: connectToDatabase(), RBAC
│   ├── admin_template.php          ← HTML shell (navbar, sidebar)
│   ├── smtp_helper.php             ← єдина SMTP-функція (SSL/TLS/none)
│   ├── smtp_settings.php           ← налаштування SMTP через UI
│   ├── updater.php                 ← автооновлення з GitHub
│   ├── support.php                 ← тікет-система підтримки
│   ├── support_reply.php           ← відповіді розробника (без логіну)
│   ├── backup.php                  ← резервне копіювання
│   ├── db_migrate.php              ← міграція SQLite → MySQL
│   └── SQLAdmin/phpadmin.php       ← phpLiteAdmin (подвійний захист)
│
├── data/                           ← ЗАКРИТА директорія (.htaccess: Deny)
│   ├── BD/database.sqlite          ← SQLite БД
│   ├── DbDriver.php                ← абстракція SQLite/MySQL
│   ├── migrations.php              ← автоматичні міграції схеми
│   ├── logs/                       ← журнали дій
│   └── backups/                    ← файли бекапів (db/ + files/)
│
├── uploads/                        ← медіафайли (.htaccess: заборона PHP)
├── assets/                         ← CSS, JS, зображення
├── templates/                      ← публічні PHP-шаблони
├── views/                          ← Twig-шаблони
└── vendor/                         ← Composer (Twig та ін.)

# ПОЗА webroot (рекомендовано):
../cms_storage/
└── .env                            ← секрети: паролі, API-ключі
```

### Потік запиту

**Публічний запит:**
```
Apache/IIS → router.php → config.php (fly_db, .env) → templates/page.php → Twig → HTML
```

**Адміністративний запит:**
```
.htaccess Basic Auth → admin/*.php → session check → functions.php → бізнес-логіка → admin_template.php → HTML
```

### Центральні компоненти

| Файл | Роль |
|------|------|
| `config.php` | `fly_db()`, `env()`, `get_setting()`, `ts()`, security headers — база для всіх файлів |
| `functions.php` | `connectToDatabase()`, `is_admin()`, CRUD users, `db_transaction()` |
| `admin_template.php` | HTML shell: navbar, sidebar, Bootstrap — підключають всі admin/*.php |
| `data/migrations.php` | Автоматичне оновлення схеми БД (lock-файл, одноразове виконання) |
| `admin/smtp_helper.php` | `fly_smtp_send()` — єдина SMTP-функція для всієї CMS |

---

## 👥 Ролі та доступ (RBAC)

| Роль | Рівень | Права |
|------|--------|-------|
| `superadmin` | 👑 Власник | Повний доступ + метадані CMS + очистка логів + оновлення CMS |
| `admin` | 🛡 Адмін | Контент, користувачі, налаштування, бекапи, файли, SMTP |
| `redaktor` | ✏️ Редактор | Сторінки, записи, медіа, меню, нотатки |
| `user` | 👤 Юзер | Авторизований, без доступу до адмінки |

---

## 🔒 Безпека

### Реалізовані механізми

- **CSP + X-Frame-Options + X-Content-Type-Options + Referrer-Policy** — централізовано через `fly_send_security_headers()`
- **PDO prepared statements** скрізь — захист від SQL-ін'єкцій
- **bcrypt (cost 12)** для всіх паролів
- **CSRF-токени** `bin2hex(random_bytes(32))` у кожній формі адмінки
- **TOTP 2FA** (RFC 6238) через `data/totp.php` + QR через qrserver.com
- **HTTP Basic Auth** для `/admin/` — подвійний рубіж захисту
- **Session**: `httponly=true`, `secure=true`, `SameSite=Lax`, `session_regenerate_id()` після логіну
- **Rate limiting** для AI API: 10 запитів / 60 сек з IP (`data/rate_limiter.php`)
- **IP-алерти** при вході з нового IP — badge у dashboard
- **`.env` поза webroot** — жодних секретів у коді
- **`.htaccess`** блокує доступ до `.env`, `.git`, `.bak`, `.config`

### Обов'язковий чекліст production

- [ ] Видалити `install.php` після встановлення
- [ ] `.env` у `../cms_storage/.env` (поза webroot)
- [ ] HTTP Basic Auth для `/admin/`
- [ ] HTTPS з автоматичним redirect HTTP→HTTPS
- [ ] `data/` закрита через `.htaccess: Deny from all`
- [ ] `key.txt` поза webroot або закрито `.htaccess`
- [ ] 2FA для superadmin
- [ ] `PHPLITEADMIN_PASSWORD` — надійний пароль у `.env`
- [ ] Автобекап налаштовано в Адмінці → Бекап

---

## 🗄 База даних

### SQLite PDO Singleton

```php
// fly_db() — один екземпляр на весь запит
static $instance = null;
if ($instance !== null) return $instance;
// шукає у: FLY_STORAGE_ROOT/data/BD/database.sqlite → FLY_ROOT/data/BD/database.sqlite
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 3000');
```

### Основні таблиці

| Таблиця | Призначення |
|---------|-------------|
| `posts` | Публікації: title, slug, content, draft, published_at, views |
| `pages` | Сторінки: slug, meta_title, meta_description, visibility |
| `users` | Логін, bcrypt-пароль, роль, totp_enabled, totp_secret |
| `settings` | Ключ-значення: site_title, cms_version, meta_description... |
| `theme_settings` | 70+ CSS/UI параметрів теми |
| `invitations` | Одноразові токени запрошень |
| `support_tickets` | Тікети підтримки з reply_token |
| `support_messages` | Повідомлення переписки (user / support) |

Міграції запускаються **автоматично** при кожному підключенні через `connectToDatabase()`. Lock-файл гарантує одноразове виконання кожної міграції.

### Перехід на MySQL

1. Адмінка → ⚙️ Керування → 🔄 Міграція на MySQL
2. Введіть параметри MySQL → **Перевірити з'єднання**
3. **Створити таблиці** (накатується `mysql_schema.sql`, 16 таблиць)
4. **Перенести дані** (INSERT IGNORE — без дублів)
5. **Перемкнути** (у `.env` записується `DB_DRIVER=mysql`)

> **Відкат:** кнопка "Повернутися до SQLite" в тому ж інтерфейсі. SQLite-файл зберігається як резервна копія.

---

## 📧 SMTP

Власна реалізація `fly_smtp_send()` в `admin/smtp_helper.php` — без PHPMailer.

**Налаштування через UI:** Адмінка → ⚙️ Керування → Налаштування SMTP (або `/admin/smtp_settings.php`)

| Провайдер | Хост | Порт | Тип |
|-----------|------|------|-----|
| Gmail | `smtp.gmail.com` | 587 | TLS |
| Outlook | `smtp.office365.com` | 587 | TLS |
| UKR.net | `smtp.ukr.net` | 465 | SSL |
| Meta.ua | `smtp.meta.ua` | 465 | SSL |
| SendGrid | `smtp.sendgrid.net` | 587 | TLS |

> **Gmail і UKR.net** вимагають **пароль додатків** (App Password): `myaccount.google.com/apppasswords` / `accounts.ukr.net` → Безпека → Паролі для зовнішніх програм.
>
> **Meta.ua**: увімкніть SMTP у налаштуваннях вебпошти → Зовнішній доступ.

---

## 🔄 Оновлення

Система оновлень вбудована: `/admin/updater.php`

При відкритті сторінки **автоматично** перевіряється останній реліз на `github.com/fly380/fly-cms`. Знайшовши новішу версію — показує changelog і кнопку встановлення.

**Процес оновлення:**
1. Автоматичний бекап БД → `data/backups/db/pre_update_YYYYMMDD.sqlite`
2. Завантаження ZIP-архіву релізу з GitHub
3. Розпакування у тимчасову папку
4. Копіювання файлів з пропуском захищених
5. Оновлення `cms_version` в БД
6. Видалення тимчасових файлів

**Захищені файли — ніколи не перезаписуються:**
`.env`, `data/`, `uploads/`, `config.php`, `key.txt`, `web.config`, `.htaccess`

---

## 🛠 Технічна підтримка

Тікет-система в адмінці: `/admin/support.php`

- Категорії: баг, нова функція, безпека, хостинг, оновлення
- Пріоритети: низький / звичайний / високий / критичний
- При кожному повідомленні розробник отримує email з кнопкою **"Відповісти в CMS"**
- Відповідь розробника зберігається в БД і одразу видна в переписці
- Авто-оновлення переписки кожні 15 секунд (polling)

---

## ❓ Вирішення типових проблем

| Проблема | Рішення |
|----------|---------|
| Білий екран після завантаження | Відсутній `vendor/` — запустіть `composer install --no-dev` |
| Адмінка повертає 401 | HTTP Basic Auth не налаштовано або невірний пароль у `.htpasswd` |
| `database is locked` | Перейдіть на MySQL або збільшіть `busy_timeout` у `config.php` |
| Файли не завантажуються | Права `uploads/` (755) і `upload_max_filesize` у `.user.ini` / `php.ini` |
| CMS не знаходить `.env` | Шукає у `../cms_storage/.env` і `webroot/.env` — перевірте наявність файлу |
| SMTP не відправляє | `SMTP_HOST` не порожній у `.env`; для Gmail/UKR.net потрібен App Password |
| Шаблони не рендеряться | Відсутній `vendor/` — запустіть `composer install --no-dev` |
| GitHub API: Not Found | Не створено Release на GitHub — виконайте `git tag v2.x.x` + опублікуйте реліз |
| `install.php` — "вже встановлено" | Видаліть `data/.installed` і `.env` для переінсталяції |

---

## 📂 Корисні команди

```bash
# Встановлення залежностей
composer install --no-dev --optimize-autoloader

# Права на директорії (Linux/Mac)
chmod 755 data/ uploads/ assets/
chmod 644 config.php index.php router.php
chmod 640 .env

# Перевірка PHP розширень
php -m | grep -E "pdo|sqlite|zip|gd|mbstring"

# Ручне оновлення версії в БД
sqlite3 data/BD/database.sqlite \
  "UPDATE settings SET value='2.7.0-AI' WHERE key='cms_version';"

# Завантаження на сервер через rsync
rsync -avz --exclude='.env' --exclude='data/BD/' \
  ./fly-cms/ user@server.com:/var/www/your-site/
```

---

## 📋 URL адреси адмінки

| URL | Що відкриває |
|-----|--------------|
| `/admin/` | Dashboard |
| `/admin/updater.php` | Оновлення CMS з GitHub |
| `/admin/smtp_settings.php` | Налаштування SMTP |
| `/admin/support.php` | Технічна підтримка |
| `/admin/backup.php` | Резервне копіювання |
| `/admin/db_migrate.php` | Міграція на MySQL |
| `/admin/site_settings.php` | Theme Builder |
| `/admin/meta_settings.php` | Метадані CMS (superadmin) |
| `/admin/SQLAdmin/` | phpLiteAdmin |

---

## 📋 Changelog

| Версія | Основні зміни |
|--------|---------------|
| **[2.7.0-AI](https://github.com/fly380/fly-cms/releases/tag/v2.7.0-AI)** | Тікет-підтримка, `smtp_helper.php` (SSL/TLS), SMTP через UI, автооновлення GitHub, запрошення з email, PHP 7.x сумісність |
| 2.6.5-AI | Секрети (`SMTP_PASSWORD`, `PHPLITEADMIN_PASSWORD`) перенесено у `.env` |
| 2.6.4-AI | Файловий менеджер з редагуванням файлів |
| 2.6.3-AI | Резервне копіювання БД і файлів, автобекап |
| 2.6.2-AI | Нотатки в адмінці (кольори, закріплення, нагадування) |
| 2.6.1-AI | Запрошення для реєстрації |
| 2.6.0-AI | 2FA (TOTP) через Google Authenticator / Authy |
| 2.5.x-AI | Медіабібліотека, блог із записами, випадаючі меню |
| 2.0.x-AI | AI-помічник (Groq), ролі редактора, безпека сесій |
| 1.8.x | reCAPTCHA v3, drag-and-drop меню, захист сесій |
| 1.7.0 | Перехід з JSON на SQLite |
| 1.0.0 | Початковий реліз |

Повний changelog: [CHANGELOG.md](CHANGELOG.md)

---

## 📄 Ліцензія

MIT — вільне використання, модифікація та розповсюдження з зазначенням авторства.

---

<div align="center">

Розроблено з ❤️ &nbsp;&nbsp;·&nbsp;&nbsp; [fly380.it@gmail.com](mailto:fly380.it@gmail.com) &nbsp;&nbsp;·&nbsp;&nbsp; [github.com/fly380/fly-cms](https://github.com/fly380/fly-cms)

</div>