# ✈ fly-CMS

Проста, безпечна і розширювана PHP CMS з AI-помічником, підтримкою SQLite/MySQL та зручною адмінпанеллю.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0%2B-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?logo=bootstrap&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)

---

## Можливості

| Модуль | Опис |
|--------|------|
| 📄 Сторінки | Створення, редагування, чернетки, видимість за ролями |
| 📝 Записи / Блог | Повноцінний блог з категоріями та тегами |
| 🖼 Медіабібліотека | Завантаження файлів, транслітерація назв, пошук |
| 🤖 AI-помічник | Генерація контенту через Groq API |
| 👥 Користувачі | Ролі: superadmin / admin / redaktor / user |
| 🔐 2FA | Двофакторна автентифікація (TOTP / Google Authenticator) |
| ✉️ Запрошення | Одноразові посилання для реєстрації з надсиланням email |
| 📧 Email / SMTP | Власний SMTP без PHPMailer, підтримка SSL/TLS |
| 🛠 Підтримка | Тікет-система з відповідями розробника прямо в CMS |
| 💾 Бекап | Резервне копіювання БД і файлів, автобекап |
| 🔄 Оновлення | Автооновлення з GitHub Releases |
| 🗄 MySQL | Міграція з SQLite на MySQL через UI-wizard |
| 📁 Файловий менеджер | Перегляд і редагування файлів сервера |
| 📋 Логи | Журнал дій користувачів |
| 🔑 phpLiteAdmin | Вбудований інтерфейс для SQLite |

---

## Вимоги

- PHP 8.1+
- Розширення: `pdo_sqlite`, `pdo_mysql` (для MySQL), `gd`, `zip`, `mbstring`
- Web-сервер: Apache / Nginx / IIS
- `allow_url_fopen = On` (для AI та оновлень)

---

## Встановлення

### 1. Завантаження

```bash
git clone https://github.com/YOUR_USERNAME/fly-cms.git
cd fly-cms
```

### 2. Інсталятор

Відкрийте у браузері:

```
https://your-domain.com/install.php
```

Інсталятор:
- перевіряє вимоги PHP
- налаштовує SQLite або MySQL
- створює superadmin акаунт
- записує `.env` поза webroot
- видаляє себе після успіху

### 3. Конфігурація `.env`

Файл `.env` зберігається **поза webroot** у `../cms_storage/.env`:

```env
# База даних
DB_DRIVER=sqlite          # або mysql

# MySQL (якщо обрано)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=flycms
DB_USER=flycms_user
DB_PASS=secret

# SMTP
SMTP_ENABLED=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your@gmail.com
SMTP_PASSWORD=xxxx xxxx xxxx xxxx   # App Password для Gmail
SMTP_FROM_NAME=fly-CMS
SMTP_FROM_EMAIL=your@gmail.com
SMTP_ENCRYPTION=tls

# AI (Groq)
GROQ_API_KEY=gsk_...

# Оновлення з GitHub
GITHUB_OWNER=your-username
GITHUB_REPO=fly-cms
GITHUB_TOKEN=ghp_...               # тільки для приватних репо
```

---

## Структура проекту

```
fly-cms/
├── admin/                  # Адмінпанель
│   ├── admin_template.php  # Шаблон адмінки (sidebar, navbar)
│   ├── smtp_helper.php     # Єдина SMTP-функція (SSL/TLS/none)
│   ├── smtp_settings.php   # Налаштування SMTP + GitHub у .env
│   ├── updater.php         # Система оновлень з GitHub
│   ├── support.php         # Тікет-система підтримки
│   ├── support_reply.php   # Сторінка відповіді розробника (без логіну)
│   ├── invite.php          # Запрошення + email відправка
│   ├── backup.php          # Резервне копіювання
│   ├── db_migrate.php      # Міграція SQLite → MySQL
│   └── ...
├── data/
│   ├── DbDriver.php        # Абстракція SQLite/MySQL
│   └── BD/                 # SQLite база (поза webroot в production)
├── templates/              # Публічні шаблони (login, register...)
├── config.php              # Центральна конфігурація + fly_db()
├── install.php             # WordPress-style інсталятор
└── .htaccess
```

---

## Ролі користувачів

| Роль | Можливості |
|------|-----------|
| `superadmin` | Повний доступ, оновлення CMS, видалення даних |
| `admin` | Управління контентом, користувачами, налаштуваннями |
| `redaktor` | Створення і редагування сторінок та записів |
| `user` | Тільки перегляд захищеного контенту |

---

## SMTP — важливо

fly-CMS використовує **власну SMTP реалізацію** без PHPMailer.

| Провайдер | Хост | Порт | Тип |
|-----------|------|------|-----|
| Gmail | `smtp.gmail.com` | 587 | TLS |
| Outlook | `smtp.office365.com` | 587 | TLS |
| UKR.net | `smtp.ukr.net` | 465 | SSL |
| Meta.ua | `smtp.meta.ua` | 465 | SSL |

> **Gmail і UKR.net**: потрібен пароль додатків, не звичайний пароль.  
> **Meta.ua**: потрібно увімкнути SMTP у налаштуваннях вебпошти.

---

## Оновлення

Система оновлень доступна в адмінці → **⚙ Керування → 🔄 Оновлення CMS**.

1. Налаштуйте `GITHUB_OWNER` і `GITHUB_REPO` у `.env` або через `/admin/smtp_settings.php`
2. При відкритті сторінки автоматично перевіряється остання версія
3. Бекап БД створюється автоматично перед кожним оновленням
4. Захищені файли (`.env`, `data/`, `uploads/`, `config.php`) **ніколи не перезаписуються**

---

## Безпека

- Всі секрети у `.env` поза webroot
- CSRF-токени у всіх формах
- bcrypt (cost 12) для паролів
- TOTP 2FA для адміністраторів
- CSP, X-Frame-Options, X-Content-Type-Options заголовки
- Session regeneration після логіну
- Обмеження спроб входу

---

## Ліцензія

MIT — вільне використання, модифікація та розповсюдження.

---

<p align="center">Розроблено з ❤ | <a href="mailto:fly380.it@gmail.com">fly380.it@gmail.com</a></p>
