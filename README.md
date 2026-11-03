# @CHOIR_CLUB_ALA_bot — бот хора «Club Ala»

Telegram-бот для расписания репетиций, отметок посещаемости, оплаты и базы нот/записей хора.

**Статус:** фаза проектирования, стек не выбран (кандидаты: PHP 8.2 + Nutgram или Node.js + grammY).  
**Хостинг:** shared hosting [alabot.liveband.kz](https://alabot.liveband.kz) — MySQL, cron, HTTPS; без SSH.  
**Часовой пояс:** `Asia/Almaty` (UTC+5).  
**Username бота:** [@CHOIR_CLUB_ALA_bot](https://t.me/CHOIR_CLUB_ALA_bot)

## Документация

| Документ | Содержание |
|----------|------------|
| [docs/TZ.md](docs/TZ.md) | Техническое задание |
| [docs/database.md](docs/database.md) | Схема БД и ER-диаграмма |
| [docs/schema.sql](docs/schema.sql) | Переносимый SQL DDL (MySQL; отличия SQLite в комментариях) |
| [docs/scenarios.md](docs/scenarios.md) | Сценарии по ролям и расписание фоновых задач |
| [texts/ru.json](texts/ru.json) | Тексты сообщений и кнопок бота |

## Быстрый старт (после выбора стека)

1. Скопировать `.env.example` → `.env` и заполнить переменные.
2. Применить `docs/schema.sql` к MySQL (или адаптировать для SQLite локально).
3. Реализовать приложение по ТЗ и сценариям; тексты брать из `texts/ru.json`.

Код приложения в этом репозитории пока **не** добавлен.
