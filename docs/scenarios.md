# Сценарии и фоновые задачи

Все пользовательские тексты и подписи кнопок — в [../texts/ru.json](../texts/ru.json).

---

## 1. Общие правила UI

- **Главное меню** (Reply Keyboard): только у `active`; набор кнопок зависит от роли (`MenuKeyboard`, `MenuButtonAccess`).
- **Inline-меню:** в подвале часто **‹ Назад** и **✖ Закрыть** (`InlineMenuHelper::CLOSE_CALLBACK` = `ui:x`). Закрытие очищает `user_flows` и заменяет текст на `ui.menu_closed`.
- **Пошаговый ввод** (`user_flows`): перенос репетиции, правка текстов оплаты, смена админ-чата, «Не приду», ввод причины после опроса, поиск песни, профиль, загрузка материалов. **Нажатие кнопки главного меню** сбрасывает все активные flows и выполняет действие меню (`MessageHandler::clearActiveFlows`).
- **`pending` / `blocked` / `rejected`:** меню скрыто; доступ только к сообщениям статуса (см. handlers регистрации и блокировки).
- **Роли в БД:** `member`, `admin` (помощник), `conductor`, `owner` (в UI и «О боте» — **техспец**). Бейджи: `admin.role_badge_*` в `ru.json`.
- **Админ-чат:** `AdminChatResolver` — сначала `bot_settings.admin_chat_id`, иначе `ADMIN_CHAT_ID` из `.env`. Техспец меняет чат через **«Админ-чат»**. Ссылка для кнопки: супергруппа `-100…` → `t.me/c/…`, иначе `ADMIN_CHAT_LINK` из `.env` (`AdminChatLink`).

---

## 2. Регистрация

| Шаг | Действие | Ответ / эффект |
|-----|----------|----------------|
| 1 | `/start` | `registration.welcome` → ввод имени |
| 2 | Текст имени | `registration.ask_part` + inline: 7 партий |
| 3 | Выбор партии | `registration.pending_user`; заявка в **админ-чат** (если привязан): `registration.admin_new_request` + **Принять** / **Отклонить** |
| 4a | **Принять** в админ-чате (owner / conductor / admin с правом модерации) | Участнику: `registration.accepted`; `status=active`; меню |
| 4b | **Отклонить** | `registration.rejected`; `status=rejected` |

Повторный `/start` у `active`: `registration.already_registered` + меню.

---

## 3. Участник

### 3.1. Расписание

Кнопка **«Расписание»** → `schedule.list_header` и список или `schedule.empty`. После правок расписания админом — push `schedule.changed` с inline **«Расписание»**.

### 3.2. Напоминание накануне (cron 12:00 D-1)

- Сообщение `reminder.day_before` + **Да** / **Нет** (`att:db:{id}:y|n`).
- **Да** — без отметки «предупредил» (или снятие предупреждения, если был «Нет» с причиной).
- **Нет** — запрос причины: текст **или** inline **«Нет причины»** (`att:sk`).
  - С **причиной** → mark `warned`, source `day_before`; причина дирижёру (`absence.forward_reason`).
  - **«Нет причины»** → mark `unexcused`, source `day_before`; правила дисциплины (§3.5).

### 3.3. Check-in

| Момент | Поведение |
|--------|-----------|
| `starts_at` (cron) | Опрос `checkin.question` + **Да** / **Нет**, если нет mark `warned` |
| **Да** до `ends_at` | `attended`; сброс `consecutive_unexcused` |
| **Нет** | Как накануне: причина или **«Нет причины»** → `warned` или `unexcused` (source `check_in`) |
| До `ends_at` нет ответа (cron закрытия) | `unexcused`, source `system` |

### 3.4. «Не приду»

1. Список предстоящих репетиций (inline).
2. После выбора — **обязательная причина** одним сообщением (`absence.ask_reason_required`). Пустое сообщение не принимается. Кнопки **«Нет причины»** в этом сценарии **нет**.
3. С причиной → mark `warned`, source `absence_button`; дирижёру `absence.notify_conductor`; `absence.confirmed`.

### 3.5. Серия неуважительных пропусков

После `unexcused` (не после `warned`):

- `consecutive_unexcused == 3` → участнику `discipline.third_strike`
- `>= 4` → участнику `discipline.expelled`; дирижёру `discipline.expelled_notify_conductor`; `status=blocked` (`block_events`, reason `auto`)
- Сброс счётчика: `attended` или `warned` (в т.ч. заранее через «Не приду» с причиной)

### 3.6. Оплата

| Событие | Поведение |
|---------|-----------|
| 23-е, 12:00 | `payment_reminder_1` (текст: `PaymentReminderTextService`, ключ `payment_reminder_1_text` или `payment.reminder_first`) + **«Я оплатил(а)»** за **следующий** месяц; **не отправлять**, если оплата за этот период уже подтверждена дирижёром |
| **Я оплатил(а)** | `payment.member_marked`; дирижёру `payment.conductor_confirm_prompt` + **Подтвердить** |
| 1-е, 12:00 | Подтверждено дирижёром → пропуск; иначе при отметке участника → `payment.mismatch`; иначе второе напоминание (`payment_reminder_2_text` / `payment.reminder_second`) |

Дирижёр/техспец: **«Админ: оплата»** → месяц → статусы; **«Тексты напоминаний»** → правка/сброс текстов в `bot_settings`.

### 3.7. Песни и записи

- **«Найти песню»** → `songs.ask_title` → поиск без учёта регистра; 0 / 1 / **несколько** (`songs.pick_from_list` + inline) → карточка: **Ноты**, **Транслит**, **Записи партий**.
- **«Мои записи»** — записи для `voice_part` пользователя.

---

## 4. Помощник и дирижёр: расписание и посещаемость

**«Админ: расписание»** (помощник, дирижёр, техспец): ближайшие репетиции, перенос, адрес, отмена, внеплановые, шаблоны (`ScheduleAdminHandler`, flows в `user_flows`).

**«Админ: посещаемость»** (дирижёр/техспец): ручные отметки **Был** / **Предупредил** / **Пропуск**, source `manual`.

---

## 5. Только дирижёр и техспец

| Меню | Сценарий |
|------|----------|
| **Админ: история** | Участник → отметки по репетициям и сводка по оплате (`AdminHistoryHandler`) |
| **Админ: оплата** | Месяц → список; подтверждение; **Тексты напоминаний** (`pay:rt:*`) |
| **Админ: помощники** | Назначить / снять `admin` |
| **Админ: участники** | Блок / разблок; при разблокировке `consecutive_unexcused=0`, `block_events` |

---

## 6. Админ-чат и материалы

Чат определяется `AdminChatResolver` (см. §1). Сообщения **только из этого чата** могут запускать загрузку.

### 6.1. Файл с подписью или пошаговый flow

1. Файл в админ-чате → flow: песня (список / новая), тип (ноты / транслит / запись).
2. Для **записи** — выбор **нескольких партий** (toggle + **«Готово»**, `materials.ask_part_multi`); ноты/транслит — партия по правилам типа.
3. Дубликат `file_unique_id` → `materials.duplicate_card`.
4. Notes/translit: замена current с подтверждением; recording: новая строка или управление списком.

### 6.2. Личка

**«Админ: материалы»** — список, замена/удаление записей, кнопка **«Открыть админ-чат»** (если есть URL).

---

## 7. Техспец

- **Назначить дирижёра** — один `conductor` в БД.
- **Админ-чат** — просмотр, **Сменить чат** (ввод id, проверка что бот в чате и **админ**), запись `bot_settings.admin_chat_id`, уведомление в новый чат.

---

## 8. Фоновые задачи (cron)

Вызов: `public/cron.php` (HTTP с `key=` или CLI). Пояс `TIMEZONE`. Опционально `FAKE_NOW` в `.env`.

| `job_type` | Условие | Получатели | `job_key` (пример) |
|------------|---------|------------|-------------------|
| `schedule_generate` | 1 раз/сутки, час ≥ 3 | система | `schedule_generate:{YYYY-MM-DD}` |
| `day_before_reminder` | 12:00, репетиция завтра | active users | `day_before:{rehearsal_id}:{user_id}` |
| `checkin_open` | `starts_at` | active без `warned` | `checkin_open:{rehearsal_id}:{user_id}` |
| `checkin_close` | `ends_at` | без ответа | `checkin_close:{rehearsal_id}:{user_id}` |
| `payment_reminder_1` | день/час из `bot_settings` | active payers | `payment_r1:{YYYY-MM}:{user_id}` |
| `payment_reminder_2` | день/час из `bot_settings` | см. §3.6 | `payment_r2:{YYYY-MM}:{user_id}` |

Идемпотентность: `job_deliveries` (`tryClaim` / `wasDelivered`).

### 8.1. `payment_reminder_2`

Для каждого active user и **текущего** месяца: подтверждено дирижёром → skip (с записью delivery); member claimed без confirm → `payment.mismatch`; иначе текст второго напоминания.

---

## 9. Webhook

- `public/webhook.php`, проверка `WEBHOOK_SECRET`.
- Обработка: личные сообщения, `callback_query`, сообщения в **разрешённом админ-чате**.

---

## 10. Согласованность с ТЗ

Детали ролей и NFR — [TZ.md](TZ.md); схема — [database.md](database.md).
