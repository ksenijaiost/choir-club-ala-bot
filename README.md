# @CHOIR_CLUB_ALA_bot — бот хора «Club Ala»

Telegram-бот для расписания репетиций, отметок посещаемости, оплаты и базы нот/записей хора.

**Стек:** PHP 8.2+, [Nutgram](https://nutgram.dev/) 4.x (Composer), PDO (**SQLite** локально, **MySQL** на сервере).  
**Часовой пояс:** `Asia/Almaty` (UTC+5), задаётся `TIMEZONE` в `.env`.  
**Username:** [@CHOIR_CLUB_ALA_bot](https://t.me/CHOIR_CLUB_ALA_bot)

## Документация

| Документ | Содержание |
|----------|------------|
| [docs/TZ.md](docs/TZ.md) | Техническое задание |
| [docs/database.md](docs/database.md) | Схема БД и ER-диаграмма |
| [docs/schema.sql](docs/schema.sql) | Эталон DDL (MySQL); применение через `migrations/` |
| [docs/scenarios.md](docs/scenarios.md) | Сценарии и cron |
| [texts/ru.json](texts/ru.json) | Тексты бота |

## Функции по ролям

Роли в БД: `member`, `admin` (**помощник**), `conductor` (**дирижёр**), `owner` (**техспец** в интерфейсе и справке «О боте»). Пункт меню **«О боте»** повторяет те же разделы.

### Участник (`member`)

- **Расписание** — ближайшие репетиции.
- **Не приду** — выбор предстоящей репетиции, затем **обязательная** причина одним сообщением; без причины отметка «предупредил» не ставится.
- Напоминание **накануне в 12:00** и опрос **«Ты на репетиции?»** в начале репетиции: при отказе можно написать причину или нажать **«Нет причины»** — тогда пропуск **неуважительный**; с причиной — уважительный («предупредил»).
- **Пропуски:** три неуважительных подряд — предупреждение; на четвёртом бот блокирует доступ до разблокировки дирижёром.
- **Оплата:** дирижёр подтверждает взнос; 23-го в 12:00 — напоминание за **следующий** месяц, 1-го в 12:00 — за **текущий**; если оплата за этот месяц уже подтверждена, напоминания не приходят. Кнопка **«Я оплатил(а)»**.
- **Найти песню** / **Мои записи** — ноты, транслит и записи по партии.
- **Мой профиль** — имя и партия.

### Помощник (`admin`)

- **Админ: расписание** — шаблоны, разовые репетиции, перенос, адрес, отмена; при изменениях рассылка активным участникам.
- **Админ: материалы** — список в личке; загрузка файлов в **админ-чат** (бот проводит по шагам: песня, тип, для записи — **несколько партий** на один файл, кнопка **«Готово»**).

### Дирижёр (`conductor`)

- Всё, что у помощника, плюс:
- **Админ: посещаемость** — отметки и ручные правки.
- **Админ: оплата** — сводка по месяцам, подтверждение; **«Тексты напоминаний»** — правка текстов 23-го и 1-го (плейсхолдер `{month}`), сброс к стандартным.
- **Админ: история** — посещаемость и оплаты по участнику.
- **Админ: помощники** / **Админ: участники** — назначение помощников, блокировка и разблокировка.

### Техспец (`owner`)

- **Назначить дирижёра** — из активных участников (прежний дирижёр снова `member`).
- **Админ-чат** — просмотр и смена чата для материалов и заявок (бот должен быть **администратором** в новом чате).
- Те же инструменты, что у дирижёра.

Общее: **inline-меню** — **«✖ Закрыть»** (`ui:x`); нажатие **кнопки главного меню** во время пошагового ввода сбрасывает `user_flows` и выполняет действие меню.

## Переменные окружения (`.env.example`)

Скопируйте `.env.example` → `.env`. Ниже — все ключи из примера (включая закомментированные).

| Переменная | Назначение |
|------------|------------|
| `BOT_TOKEN` | Токен от [@BotFather](https://t.me/BotFather) |
| `OWNER_TELEGRAM_ID` | Telegram user id **техспеца** (обязательно); в БД `role=owner`, без регистрации |
| `CONDUCTOR_TELEGRAM_ID` | *Опционально.* Legacy: при первом действии этот user может стать дирижёром в БД |
| `ADMIN_CHAT_ID` | *Запасной* id приватной группы для материалов и заявок, если в БД ещё нет `bot_settings.admin_chat_id` |
| `ADMIN_CHAT_LINK` | *Опционально.* Готовая ссылка `t.me/...` для кнопки **«Открыть админ-чат»**, если id **не** супергруппы (`-100…`). Для супергруппы ссылка строится из id автоматически |
| `DB_CONNECTION` | `sqlite` или `mysql` |
| `DB_HOST` | Хост MySQL (для SQLite не используется) |
| `DB_PORT` | Порт MySQL |
| `DB_DATABASE` | Путь к файлу SQLite или имя БД MySQL |
| `TIMEZONE` | Пояс для cron и отображения времени (по умолчанию `Asia/Almaty`) |
| `WEBHOOK_URL` | HTTPS URL до `public/webhook.php` на хостинге |
| `WEBHOOK_SECRET` | Секрет заголовка `X-Telegram-Bot-Api-Secret-Token` |
| `ADMIN_PAGES_KEY` | Ключ для `public/admin/migrate.php`, `public/cron.php`, `public/admin/set-webhook.php` |
| `PAYMENT_REMINDER_DAY_1` / `PAYMENT_REMINDER_DAY_2` / `PAYMENT_REMINDER_HOUR` | *Подсказка в примере; в коде читаются из `bot_settings`* (`23`, `1`, `12` по умолчанию) |
| `SCHEDULE_GENERATION_WEEKS` | *Подсказка; в коде — `bot_settings.schedule_generation_weeks` (по умолчанию `4`)* |
| `REHEARSAL_DEFAULT_DURATION_MINUTES` | *Подсказка; в коде — `bot_settings.rehearsal_default_duration_minutes` (по умолчанию `120`)* |
| `FAKE_NOW` | *Опционально.* ISO 8601 — подмена «сейчас» для CLI `public/cron.php` и тестов |

**Админ-чат:** основной id хранится в **`bot_settings.admin_chat_id`** (кнопка **«Админ-чат»** у техспеца). `ADMIN_CHAT_ID` в `.env` — fallback до первой привязки. Бот должен быть **админом** в этом чате (загрузка материалов, заявки на вступление с кнопками Принять/Отклонить).

## Cron (фоновые задачи)

Вызов: `public/cron.php` по HTTP или CLI (см. ниже). Внутри — проверка окна в поясе `TIMEZONE`; идемпотентность через `job_deliveries`.

| Задача | Когда | Что делает |
|--------|-------|------------|
| `schedule_generate` | Раз в сутки, с **03:00** местного времени | Догенерация репетиций по шаблонам на горизонт `schedule_generation_weeks` |
| `day_before_reminder` | **12:00** в день **перед** репетицией | «Будешь?» **Да** / **Нет** активным участникам |
| `checkin_open` | В **начало** репетиции (`starts_at`) | Опрос «Ты на репетиции?» тем, у кого нет отметки «предупредил» |
| `checkin_close` | В **конец** (`ends_at`) | Нет ответа → неуважительный пропуск; закрытие сессии |
| `payment_reminder_1` | День `payment_reminder_day_1` (23), час `payment_reminder_hour` (12) | Напоминание об оплате **следующего** месяца; не шлёт, если оплата за этот период уже подтверждена; текст из `bot_settings` или `texts/ru.json` |
| `payment_reminder_2` | День `payment_reminder_day_2` (1), тот же час | Напоминание за **текущий** месяц или `payment.mismatch`, если участник отметил оплату без подтверждения; при подтверждении — пропуск |

**Дисциплина пропусков:** подряд идущие **неуважительные** (`unexcused`) — на 3-м сообщение-предупреждение, на 4-м авто-блокировка и уведомление дирижёру (участнику — «изгнание»). Уважительное предупреждение или явка сбрасывает счётчик.

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

## Локальная разработка

### Требования

- **PHP 8.2+** с расширениями: `pdo`, `pdo_sqlite`, `pdo_mysql`, `mbstring`, `json`, `curl`, `openssl`
- **Composer** 2.x
- Тестовый бот от [@BotFather](https://t.me/BotFather)

```bash
cp .env.example .env   # заполните BOT_TOKEN, OWNER_TELEGRAM_ID, ADMIN_PAGES_KEY, WEBHOOK_SECRET
composer install
php public/admin/migrate.php key=ВАШ_ADMIN_PAGES_KEY
php run.php            # long polling
```

Проверка cron с подменой времени:

```bash
FAKE_NOW=2026-11-22T12:00:00+05:00 php public/cron.php
```

Одноразовый HTTP: `php -S localhost:8080 -t public` → `http://localhost:8080/admin/migrate.php?key=...`

По умолчанию: `DB_CONNECTION=sqlite`, `DB_DATABASE=storage/choir.sqlite`.

### Тесты и статический анализ

`composer test` выполняет по порядку:

1. `composer autoload-check` — PSR-4 (`bin/psr4-check.php`)
2. `composer phpstan` — PHPStan **level 2**, `--memory-limit=1G` (`phpstan.neon`, каталог `src/`)
3. `phpunit`

Правило репозитория: **один класс — один файл** (проверяется autoload-check).

## Деплой (shared hosting, без SSH)

**Сборка локально:** `composer install --no-dev --optimize-autoloader` — загрузите `vendor/` на сервер.

1. Загрузите проект (FTP/Git), `.env` на сервере с prod-значениями (`DB_CONNECTION=mysql`, `BOT_TOKEN`, `WEBHOOK_URL`, …).
2. Document root на `public/` (или URL с `/public/...`).
3. После **каждого обновления кода:**
   - `…/admin/migrate.php?key=ADMIN_PAGES_KEY`
   - Проверьте cron (см. ниже); при смене домена — `…/admin/set-webhook.php?key=…`
4. Cron **каждые 1–5 минут:**

```text
curl -sS "https://ваш-домен/public/cron.php?key=ADMIN_PAGES_KEY"
```

5. Перед локальным `run.php` снимите webhook (BotFather / `deleteWebhook`).

**Хостинг:** [alabot.liveband.kz](https://alabot.liveband.kz) — MySQL, cron, HTTPS.

## Лицензия

Проприетарный проект хора Club Ala.
