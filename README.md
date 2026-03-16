<div align="center">

# ✈ fly-CMS

**Самописна PHP CMS без фреймворків — швидка, безпечна, повністю під вашим контролем**

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white)](https://sqlite.org)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![License](https://img.shields.io/badge/license-MIT-22c55e?style=flat-square)](LICENSE)
[![Version](https://img.shields.io/badge/version-2.9.1--AI-2E5FA3?style=flat-square)](https://github.com/fly380/fly-cms/releases)

[Встановлення](#-встановлення) · [Архітектура](#-архітектура) · [Безпека](#-безпека) · [Оновлення](#-оновлення)

</div>

---

## Що це таке

fly-CMS — повнофункціональна система управління контентом, написана повністю вручну на PHP **без зовнішніх фреймворків**. Розроблена для малих і середніх сайтів, де важлива повна контрольованість, мінімум залежностей і зручна адмінка українською мовою.

```
Мова / Runtime    PHP 8.1+, без фреймворку
База даних        SQLite 3 (WAL-режим, foreign_keys ON)
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
| 🔄 **Оновлення** | Автооновлення з GitHub Releases: завантаження, розпакування, бекап БД |
| 📁 **Файловий менеджер** | Перегляд і редагування файлів сервера |
| 📋 **Логи** | Журнал дій адміністраторів з IP-алертами |
| 🗃 **phpLiteAdmin** | Вбудований інтерфейс для SQLite з подвійним захистом |
| 🧩 **Плагіни** | Система хуків (actions/filters) — розширення без зміни ядра |

---

## Вимоги

| Компонент | Вимога |
|-----------|--------|
| PHP | **8.1+** (рекомендовано 8.2 / 8.3) |
| Розширення | `pdo_sqlite`, `mbstring`, `json`, `gd`, `zip` |
| Веб-сервер | Apache 2.4+ / IIS 10+ / Nginx |
| Пам'ять | `memory_limit` мін. 64M, рекомендовано 128M+ |
| Завантаження | `upload_max_filesize` мін. 8M, рекомендовано 50M+ |

```bash
# Перевірка розширень
php -m | grep -E "pdo|sqlite|json|mbstring|zip|gd"
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
| 2. База даних | SQLite — інформаційний екран, нічого налаштовувати не потрібно |
| 3. Сайт | Назва, опис, GROQ API Key (необов'язково), демо-запис у блозі |
| 4. Адміністратор | Логін, пароль (bcrypt cost 12), відображуване ім'я |
| 5. Встановлення | Директорії, схема БД (20 таблиць), `.env`, lock-файл, самовидалення |

Після встановлення фінальний екран показує:
- URL сайту та адмінки
- Логін адміністратора
- Посилання на phpLiteAdmin та **пароль до нього** (збережіть зараз — більше не відображається)
- Статус GROQ API

### 3. Налаштувати `.env`

Інсталятор генерує файл автоматично у `../cms_storage/.env` (поза webroot). За потреби відредагуйте:

```env
# AI (Groq) — необов'язково, можна додати через Адмінка → Загальне
GROQ_API_KEY=gsk_...

# SMTP
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your@gmail.com
SMTP_PASSWORD=xxxx xxxx xxxx xxxx   # App Password для Gmail/UKR.net
SMTP_FROM_NAME=fly-CMS
SMTP_FROM_EMAIL=your@gmail.com
SMTP_ENCRYPTION=tls

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
├── config.php                      ← центральна конфігурація, fly_db() SQLite singleton
├── index.php                       ← контролер головної сторінки
├── router.php                      ← front-controller (URL routing)
├── install.php                     ← інсталятор (видаляється після setup)
├── web.config                      ← IIS: HTTPS redirect, URL Rewrite
│
├── admin/                          ← адмінпанель
│   ├── .htaccess                   ← HTTP Basic Auth
│   ├── functions.php               ← bootstrap: connectToDatabase(), RBAC
│   ├── admin_template.php          ← HTML shell (navbar, sidebar)
│   ├── site_settings.php           ← Theme Builder + загальні налаштування + GROQ API Key
│   ├── smtp_helper.php             ← єдина SMTP-функція (SSL/TLS/none)
│   ├── smtp_settings.php           ← налаштування SMTP через UI
│   ├── updater.php                 ← автооновлення з GitHub
│   ├── support.php                 ← тікет-система підтримки
│   ├── support_reply.php           ← відповіді розробника (без логіну)
│   ├── backup.php                  ← резервне копіювання
│   ├── menu_editor.php             ← редактор навігаційного меню
│   └── SQLAdmin/phpadmin.php       ← phpLiteAdmin (подвійний захист, CSS вбудований)
│
├── data/                           ← ЗАКРИТА директорія (.htaccess: Deny)
│   ├── BD/database.sqlite          ← SQLite БД
│   ├── DbDriver.php                ← SQLite-утиліти (upsert, tableExists, WAL checkpoint)
│   ├── migrations.php              ← автоматичні міграції схеми (версія 9, 20 таблиць)
│   ├── plugins.php                 ← ядро системи плагінів (hooks/filters)
│   ├── publish_scheduler.php       ← планувальник відкладеної публікації
│   ├── rate_limiter.php            ← файловий sliding-window rate limiter
│   ├── logs/                       ← журнали дій
│   └── backups/                    ← файли бекапів (db/ + files/)
│
├── plugins/                        ← папка плагінів
│   ├── seo-meta/                   ← Open Graph / Twitter Card мета-теги
│   └── ukr-to-lat/                 ← транслітерація slug
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
| `config.php` | `fly_db()` SQLite singleton, `get_setting()`, `ts()`, security headers |
| `data/DbDriver.php` | SQLite-утиліти: `now()`, `upsert()`, `insertIgnore()`, `tableExists()`, `walCheckpoint()` |
| `data/TwigFactory.php` | Twig singleton, кастомні фільтри (`sanitize_html`, `date_uk`, `excerpt`) |
| `data/ViewContext.php` | Будує контекст шаблонів: `getBase()`, `getIndex()`, `getPage()` |
| `data/migrations.php` | Версіонована міграція схеми через lock-файл (версія 9) |
| `data/plugins.php` | `fly_add_action()`, `fly_do_action()`, `fly_add_filter()`, `fly_apply_filters()` |

---

## 👥 Ролі та доступ (RBAC)

| Роль | Рівень | Права |
|------|--------|-------|
| `superadmin` | 👑 Власник | Повний доступ + метадані CMS + очистка логів + оновлення CMS |
| `admin` | 🛡 Адмін | Контент, користувачі, налаштування, бекапи, файли, SMTP, AI |
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
- **phpLiteAdmin CSS** вбудовується inline — прямий HTTP-доступ до файлів `SQLAdmin/` заблоковано

### Обов'язковий чекліст production

- [ ] Видалити `install.php` після встановлення
- [ ] `.env` у `../cms_storage/.env` (поза webroot)
- [ ] HTTP Basic Auth для `/admin/`
- [ ] HTTPS з автоматичним redirect HTTP→HTTPS
- [ ] `data/` закрита через `.htaccess: Deny from all`
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
$pdo->exec('PRAGMA foreign_keys = ON');
```

### Основні таблиці

| Таблиця | Призначення |
|---------|-------------|
| `posts` | Публікації: title, slug, content, draft, publish_at, visibility, meta_title |
| `pages` | Сторінки: slug, title, content, draft, visibility, custom_css, custom_js |
| `users` | Логін, bcrypt-пароль, роль, totp_enabled, totp_secret, email |
| `menu_items` | Навігаційне меню: type, visible, auth_only, visibility_role, icon |
| `main_page` | Контент блоку головної сторінки (id=1) |
| `settings` | Ключ-значення: site_title, cms_version, meta_description... |
| `theme_settings` | 70+ CSS/UI параметрів теми |
| `invitations` | Одноразові токени запрошень з require_2fa, email_sent |
| `notes` | Нотатки адміністраторів з нагадуваннями |
| `post_revisions` | Ревізії записів: title, content, saved_by, saved_at |
| `support_tickets` | Тікети підтримки з reply_token |
| `support_messages` | Повідомлення переписки (user / support) |
| `user_sessions` | Активні сесії для відстеження онлайн та IP-алертів |
| `backup_log` | Журнал резервних копій |

Міграції запускаються **автоматично** при кожному підключенні через `connectToDatabase()`. Lock-файл `data/locks/migrations.lock` гарантує одноразове виконання кожної версії (поточна: 9).

> **Проблеми зі схемою після оновлення?** Зверніться до тікет-системи підтримки або відновіть `admin/run_migrations.php` з репозиторію локально — скидає lock і примусово застосовує всі міграції. Видаліть файл після використання.

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

---

## 🤖 AI (Groq)

**Налаштування через UI:** Адмінка → ⚙️ Налаштування сайту → вкладка **Загальне** → секція «AI (GROQ API)»

Ключ зберігається у `.env` як `GROQ_API_KEY`. Також можна вказати під час встановлення на кроці 3.

Отримати безкоштовно: [console.groq.com/keys](https://console.groq.com/keys) — реєстрація, **API Keys → Create API key**.

---

## 🧩 Плагіни

Система хуків в `data/plugins.php`. Плагіни розміщуються у `plugins/<slug>/plugin.php` і вмикаються через Адмінка → 🧩 Плагіни.

```php
// Підписка на подію
fly_add_action('cms.post.saved', function(int $postId, array $data): void {
    // виконується після збереження запису
});

// Фільтрація значення
fly_add_filter('cms.post.content', function(string $content): string {
    return $content; // модифікований контент
});
```

**Вбудовані плагіни:**

| Плагін | Опис |
|--------|------|
| `seo-meta` | Додає Open Graph та Twitter Card мета-теги до записів |
| `ukr-to-lat` | Автоматична транслітерація slug при збереженні |

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

**Захищені файли — ніколи не перезаписуються:**
`.env`, `data/`, `uploads/`, `config.php`, `web.config`, `.htaccess`

---

## 🛠 Технічна підтримка

Тікет-система в адмінці: `/admin/support.php`

- Категорії: баг, нова функція, безпека, хостинг, оновлення
- Пріоритети: низький / звичайний / високий / критичний
- При кожному повідомленні розробник отримує email з кнопкою **"Відповісти в CMS"**
- Відповідь зберігається в БД і одразу видна в переписці
- Авто-оновлення переписки кожні 15 секунд (polling)

---

## ❓ Вирішення типових проблем

| Проблема | Рішення |
|----------|---------|
| Білий екран після завантаження | Відсутній `vendor/` — запустіть `composer install --no-dev` |
| Адмінка повертає 401 | HTTP Basic Auth не налаштовано або невірний пароль у `.htpasswd` |
| `database is locked` | Збільшіть `busy_timeout` у `config.php` або перевірте права на `data/BD/` |
| Файли не завантажуються | Права `uploads/` (755) і `upload_max_filesize` у `.user.ini` / `php.ini` |
| CMS не знаходить `.env` | Шукає у `../cms_storage/.env` і `webroot/.env` — перевірте наявність файлу |
| SMTP не відправляє | `SMTP_HOST` не порожній у `.env`; для Gmail/UKR.net потрібен App Password |
| Шаблони не рендеряться | Відсутній `vendor/` — запустіть `composer install --no-dev` |
| `no such column` після оновлення | Відкрийте `/admin/run_migrations.php` як admin/superadmin, потім видаліть його |
| GitHub API: Not Found | Не створено Release на GitHub — виконайте `git tag v2.x.x` + опублікуйте реліз |
| `install.php` — "вже встановлено" | Видаліть `data/.installed` для переінсталяції |
| phpLiteAdmin без стилів | CSS вбудовується inline — перевірте що `admin/SQLAdmin/phpliteadmin.css` існує |

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

# Локальний запуск для розробки
cd /your/project
php -S localhost:8080 router.php

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
| `/admin/site_settings.php` | Theme Builder + загальні налаштування + GROQ API Key |
| `/admin/support.php` | Технічна підтримка |
| `/admin/backup.php` | Резервне копіювання |
| `/admin/menu_editor.php` | Редактор меню |
| `/admin/plugins.php` | Менеджер плагінів |
| `/admin/SQLAdmin/phpadmin.php` | phpLiteAdmin |

---

## 📋 Changelog

| Версія | Основні зміни |
|--------|---------------|
| **2.9.1-AI** | Виправлено `version_compare` з суфіксом `-AI`, GITHUB_OWNER/REPO вшиті в updater, `.env` генерується з GitHub-змінними |
| **2.9.0-AI** | GROQ API Key перенесено у site_settings (вкладка Загальне), `ai_settings.php` видалено, `meta_settings.php` виключено з репо, модалка підтвердження у menu_editor |
| **2.8.0-AI** | Система плагінів (hooks/filters), SQLite-only (MySQL видалено), виправлена схема БД (20 таблиць), phpLiteAdmin CSS inline |
| **2.7.0-AI** | Тікет-підтримка, `smtp_helper.php` (SSL/TLS), SMTP через UI, автооновлення GitHub, запрошення з email |
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
