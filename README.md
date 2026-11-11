# @CHOIR_CLUB_ALA_bot — бот хора «Club Ala»

Telegram-бот для расписания репетиций, отметок посещаемости, оплаты и базы нот/записей хора.

**Стек:** PHP 8.2+, [Nutgram](https://nutgram.dev/) 4.x (Composer), PDO (**SQLite** локально, **MySQL** на сервере).  
**Статус:** скелет приложения; бизнес-логика по [docs/TZ.md](docs/TZ.md) — в разработке.  
**Хостинг:** shared [alabot.liveband.kz](https://alabot.liveband.kz) — MySQL, cron, HTTPS; **без SSH** (деплой FTP / Git в панели).  
**Часовой пояс:** `Asia/Almaty` (UTC+5).  
**Username:** [@CHOIR_CLUB_ALA_bot](https://t.me/CHOIR_CLUB_ALA_bot)

## Документация

| Документ | Содержание |
|----------|------------|
| [docs/TZ.md](docs/TZ.md) | Техническое задание |
| [docs/database.md](docs/database.md) | Схема БД и ER-диаграмма |
| [docs/schema.sql](docs/schema.sql) | Эталон DDL (MySQL); применение через `migrations/` |
| [docs/scenarios.md](docs/scenarios.md) | Сценарии и cron |
| [texts/ru.json](texts/ru.json) | Тексты бота |

## Структура проекта

```
├── run.php                 # локальный long polling
├── public/
│   ├── webhook.php         # вход webhook (WEBHOOK_SECRET)
│   ├── cron.php            # cron HTTP + CLI (ADMIN_PAGES_KEY / FAKE_NOW)
│   └── admin/
│       ├── migrate.php     # миграции БД
│       └── set-webhook.php # установка webhook
├── src/                    # bootstrap, config, PDO, handlers, jobs
├── migrations/mysql|sqlite/
├── texts/ru.json
└── storage/                # SQLite по умолчанию (gitignore)
```

## Локальная разработка (Windows / macOS)

### Требования

- **PHP 8.2+** с расширениями: `pdo`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `json`, `curl`, `openssl`
- **Composer** 2.x
- Тестовый бот от [@BotFather](https://t.me/BotFather) (токен для `.env`)

### Windows

1. Установите [PHP](https://windows.php.net/download/) (Thread Safe) и добавьте в `PATH`, либо используйте [Laragon](https://laragon.org/) / OpenServer.
2. Установите [Composer](https://getcomposer.org/download/).
3. В каталоге проекта:

```powershell
copy .env.example .env
# заполните BOT_TOKEN, ADMIN_PAGES_KEY, WEBHOOK_SECRET (любые длинные строки для локалки)
composer install
php public\admin\migrate.php key=ВАШ_ADMIN_PAGES_KEY
```

4. Запуск polling (удобно для отладки без HTTPS):

```powershell
php run.php
```

5. Проверка cron-заглушки в CLI с подменой времени:

```powershell
$env:FAKE_NOW="2026-11-22T12:00:00+05:00"
php public\cron.php
```

### macOS

```bash
brew install php composer
cp .env.example .env
# отредактируйте .env
composer install
php public/admin/migrate.php key=YOUR_ADMIN_PAGES_KEY
php run.php
FAKE_NOW=2026-11-22T12:00:00+05:00 php public/cron.php
```

Для одноразового вызова `migrate.php` / `cron.php` по HTTP поднимите встроенный сервер (document root = `public/`):

```bash
php -S localhost:8080 -t public
# http://localhost:8080/admin/migrate.php?key=...
```

### База данных локально

По умолчанию в `.env.example`: `DB_CONNECTION=sqlite`, `DB_DATABASE=storage/choir.sqlite`.  
Миграции: `migrations/sqlite/*.sql` (эталон MySQL — `migrations/mysql/` и [docs/schema.sql](docs/schema.sql)).

### /start

После `php run.php` команда `/start` отвечает заглушкой из `texts/ru.json` → `dev.start_placeholder`.

## Деплой на alabot.liveband.kz (без SSH)

1. **Локально:** `composer install --no-dev --optimize-autoloader` — папку `vendor/` нужно загрузить на хостинг (на сервере Composer может быть недоступен).
2. Загрузите проект по **FTP** или **Git** в панели хостинга (все файлы, кроме `.env`).
3. Создайте `.env` на сервере (или загрузите вне Git) с prod-настройками:
   - `DB_CONNECTION=mysql`, реквизиты MySQL из панели
   - `BOT_TOKEN`, `OWNER_TELEGRAM_ID`, `ADMIN_CHAT_ID` (опционально `CONDUCTOR_TELEGRAM_ID`)
   - `WEBHOOK_URL=https://…/public/webhook.php`
   - `WEBHOOK_SECRET`, `ADMIN_PAGES_KEY`
4. Настройте **document root** на каталог `public/` **или** URL с путём `/public/webhook.php` — как принято на вашем аккаунте.
5. Откройте в браузере (с секретным ключом):
   - `…/admin/migrate.php?key=ADMIN_PAGES_KEY`
   - `…/admin/set-webhook.php?key=ADMIN_PAGES_KEY`
6. В панели хостинга добавьте **cron** (каждые 1–5 мин):

```text
curl -sS "https://ваш-домен/public/cron.php?key=ADMIN_PAGES_KEY"
```

7. Удалите webhook перед возвратом к локальному polling: через BotFather или `deleteWebhook` — иначе Telegram не шлёт updates в `run.php`.

## Лицензия

Проприетарный проект хора Club Ala.
