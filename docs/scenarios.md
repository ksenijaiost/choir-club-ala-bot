# Сценарии и фоновые задачи

Все тексты сообщений и подписи кнопок — в [../texts/ru.json](../texts/ru.json).  
Callback-data и технические детали реализации здесь не фиксируются (stack TBD).

---

## 1. Общие правила UI

- **Главное меню** (Reply Keyboard, только `active` users): кнопки из `menu.*` в `ru.json`.
- **Inline-кнопки** — там, где указано в шагах.
- **pending** / **blocked**: любое сообщение или команда → только соответствующий текст; меню не показывается.
- **Owner** — `OWNER_TELEGRAM_ID`, без регистрации; назначает дирижёра и помощников.
- **Дирижёр** — в БД (`role=conductor`) или legacy `CONDUCTOR_TELEGRAM_ID`; в админ-чате модерацию заявок могут **owner**, **дирижёр** и **admin**.

---

## 2. Роль: новый пользователь (регистрация)

| Шаг | Кто | Действие | Ответ бота |
|-----|-----|----------|------------|
| 1 | User | `/start` | `registration.welcome` |
| 2 | User | Текст имени | `registration.ask_part` + inline: 7 партий (`parts.*`) |
| 3 | User | Нажатие партии | `registration.pending_user` |
| 3a | Bot | — | В чат `ADMIN_CHAT_ID`: `registration.admin_new_request` с `{name}`, `{part}` + inline **Принять** / **Отклонить** |
| 4a | Admin | **Принять** | User: `registration.accepted`; заявка `accepted`; user `status=active`; главное меню |
| 4b | Admin | **Отклонить** | User: `registration.rejected`; user `status=rejected` |

**Идемпотентность:** повторный `/start` у `active` → `registration.already_registered` + меню.

---

## 3. Роль: участник (member)

### 3.1. Расписание

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | Кнопка **«Расписание»** | `schedule.list_header` + список `{date}`, `{time}`, `{address}` или `schedule.empty` |
| 2 | (push) Изменение расписания админом | `schedule.changed` + inline **«Расписание»** (открывает тот же список) |

### 3.2. Напоминание накануне (бот инициирует)

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | Cron 12:00 D-1 | `reminder.day_before` + inline **Да** / **Нет** |
| 2 | **Да** | `reminder.will_attend`; при необходимости `attendance_marks` не создаётся до check-in |
| 3 | **Нет** | `reminder.will_not_attend`; mark `warned`, source `day_before`; сброс `consecutive_unexcused` |
| 4 | После **Нет** — текст от user | Переслать дирижёру `absence.forward_reason` с `{name}`, `{rehearsal_date}` |

### 3.3. Check-in

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | Cron at `starts_at` | Если нет mark `warned`: `checkin.question` + **Да** / **Нет** |
| 2 | **Да** до `ends_at` | mark `attended`; `consecutive_unexcused=0`; `checkin.thanks_yes` |
| 3 | **Нет** до `ends_at` | mark `unexcused`; инкремент счётчика; правила §8 |
| 4 | Нет ответа до `ends_at` | Cron закрытия: mark `unexcused`, source `system` |

### 3.4. «Не приду»

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | **«Не приду»** | Список предстоящих репетиций (inline) |
| 2 | Выбор репетиции | `absence.ask_reason_optional` |
| 3 | Текст или «Пропустить» | mark `warned`, source `absence_button`; дирижёру `absence.notify_conductor`; `absence.confirmed` |

### 3.5. Серия пропусков

После установки mark `unexcused`:

- если `consecutive_unexcused == 3` → `discipline.third_strike`
- если `== 4` → user: `discipline.expelled`; conductor: `discipline.expelled_notify_conductor`; рекомендуется `status=blocked`

### 3.6. Оплата

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | Cron 23-го 12:00 | `payment.reminder_first` + **«Я оплатил(а)»** |
| 2 | **Я оплатил(а)** | `payment.member_marked`; conductor: `payment.conductor_confirm_prompt` + **Подтвердить** |
| 3 | Cron 1-го 12:00 | Логика §10.3 ТЗ (три варианта текста) |

### 3.7. Поиск песен

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | **«Найти песню»** | `songs.ask_title` |
| 2 | Текст запроса | 0 → `songs.not_found`; 1 → карточка; >1 → inline список |
| 3 | Карточка | `{title}` + **Ноты** / **Транслит** / **Записи партий** |
| 4 | **Ноты** / **Транслит** | Отправить файл(ы) current `materials` или `songs.no_material` |
| 5 | **Записи партий** | Выбор партии → все current `recording` для пары (song, part) |
| 6 | **«Мои записи»** | Сразу записи для `user.voice_part` (поиск по всем песням или меню — см. реализацию; UX: список песен с записями для своей партии) |

---

## 4. Роль: помощник / дирижёр (расписание)

Доступ: пункт меню **«Админ: расписание»** (helpers + conductor).

| Шаг | Действие | Ответ |
|-----|----------|-------|
| 1 | Открыть админ-расписание | Список ближайших + inline: **Перенести**, **Адрес**, **Отменить**, **Добавить** |
| 2 | **Перенести** | Запрос новой даты/времени (пошагово) → обновить `rehearsals` → **рассылка** `schedule.changed` всем `active` |
| 3 | **Адрес** | Новый адрес для экземпляра → рассылка |
| 4 | **Отменить** | `status=cancelled` → рассылка |
| 5 | **Добавить** | Создать внеплановый `rehearsals` → рассылка |
| 6 | Настройка шаблона (дирижёр) | CRUD `schedule_templates` + перегенерация будущих экземпляров (политика merge — в коде) |

**Check-in вручную:** **«Админ: посещаемость»** → репетиция → участник → **Был** / **Предупредил** / **Пропуск** → upsert `attendance_marks`, source `manual`; пересчёт `consecutive_unexcused`.

---

## 5. Роль: только дирижёр

| Функция | Сценарий |
|---------|----------|
| История | **«Админ: история»** → inline список участников → по репетициям marks; **«Сводка за месяц»** → таблица/сообщения |
| Оплата | **«Админ: оплата»** → месяц → список: confirmed / awaiting / unpaid; tap → **Подтвердить** без отметки участника |
| Помощники | **«Админ: помощники»** → список → **Назначить** (forward/username/id) / **Снять** |
| Блокировка | **«Админ: участники»** (дирижёр/owner) → фильтр по партии → участник → **Заблокировать** / **Разблокировать**; журнал `block_events` (`manual` / `auto`); при разблокировке `consecutive_unexcused=0` |

---

## 6. Админ-чат (`ADMIN_CHAT_ID`)

Только сообщения **из этого чата** обрабатываются как загрузка материалов.

### 6.1. Файл с подписью «Название, партия»

1. Распарсить название и партию; уточнить **тип** (ноты/транслит/запись) если не однозначно — inline.
2. Проверить `file_unique_id` → при дубликате `materials.duplicate_card`.
3. Notes/translit: если есть current → `materials.replace_confirm` → да: архив старого, новый current.
4. Recording: добавить новую строку `materials`.
5. Ответ в чат: `materials.upload_success`.

### 6.2. Файл без подписи

1. `materials.ask_song` (список + **Новая песня**).
2. `materials.ask_type`.
3. `materials.ask_part` (для recording; для notes/translit part может быть NULL).
4. Далее как §6.1.

### 6.3. Управление записями

**«Админ: материалы»** в личке или команды в админ-чате: песня → партия → список записей → **Заменить** / **Удалить**.

---

## 7. Фоновые задачи (cron)

Cron на shared hosting вызывает один HTTP endpoint (например `/cron?key=ADMIN_PAGES_KEY`) **каждую минуту** или **каждые 5 минут**; внутри — проверка «наступило ли окно» в `Asia/Almaty`.

### 7.1. Таблица задач

| job_type | Когда (Asia/Almaty) | Получатели | job_key (UNIQUE) |
|----------|---------------------|------------|------------------|
| `schedule_generate` | Ежедневно 03:00 | — (система) | `schedule_generate:{YYYY-MM-DD}` |
| `day_before_reminder` | 12:00 в D-1 | каждый active user | `day_before:{rehearsal_id}:{user_id}` |
| `checkin_open` | `starts_at` репетиции | users без `warned` | `checkin_open:{rehearsal_id}:{user_id}` |
| `checkin_close` | `ends_at` | users без ответа | `checkin_close:{rehearsal_id}:{user_id}` |
| `payment_reminder_1` | День `payment_reminder_day_1`, час `payment_reminder_hour` | active members | `payment_r1:{YYYY-MM}:{user_id}` |
| `payment_reminder_2` | День `payment_reminder_day_2`, тот же час | см. логику ниже | `payment_r2:{YYYY-MM}:{user_id}` |

`{YYYY-MM}` для оплаты — **целевой месяц взноса** (следующий календарный месяц при напоминании 23-го).

### 7.2. Идемпотентность

1. Перед отправкой вычислить `job_key`.
2. `INSERT INTO job_deliveries (job_key, …)` — при нарушении UNIQUE пропустить отправку.
3. Транзакция: insert delivery **после** успешного `sendMessage` (или insert «pending» + update — на выбор реализации, но дубликаты недопустимы).

### 7.3. `schedule_generate`

- Поддерживать горизонт `schedule_generation_weeks` от текущей даты (по умолчанию **4** недели, см. `bot_settings` / [schema.sql](schema.sql)).
- Создавать только отсутствующие экземпляры по активным `schedule_templates`.
- Не перезаписывать вручную изменённые поля (флаг `template_id` + правила merge — открытый вопрос: не трогать экземпляры с `updated_at > generated`).

### 7.4. `day_before_reminder`

- Только `rehearsals.status=scheduled`.
- Пропускать cancelled и прошедшие.

### 7.5. `checkin_close`

- Если нет mark — создать `unexcused`, применить discipline.
- Закрыть `check_in_sessions.closed_at`.

### 7.6. `payment_reminder_2` (1-е число)

Для каждого active user и периода (текущий месяц):

| conductor_confirmed | member_claimed | Сообщение |
|--------------------|----------------|-----------|
| да | * | не отправлять |
| нет | да | `payment.mismatch` |
| нет | нет | `payment.reminder_second` |

Запись в `job_deliveries` даже если сообщение не отправлено (чтобы не повторять проверку) — ключ `payment_r2:…` с пометкой в payload или отдельный ключ `payment_r2_skip:…`.

---

## 8. Webhook

- HTTPS endpoint; проверка `WEBHOOK_SECRET`.
- Обработка: личные сообщения, callback_query, сообщения в `ADMIN_CHAT_ID`.

---

## 9. Согласованность с ТЗ

| Требование ТЗ | Где отражено |
|---------------|--------------|
| 2 реп/нед | `schedule_templates` + `schedule_generate` |
| 12:00 D-1 | `day_before_reminder` |
| Check-in window | `checkin_open` / `checkin_close` |
| 23 / 1 оплата | `payment_reminder_*` + `bot_settings` |
| file_unique_id | `materials.uk` + сценарий §6 |
| Нет функционала pending/blocked | §1 |
