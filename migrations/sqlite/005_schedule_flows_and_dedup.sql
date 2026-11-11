CREATE TABLE IF NOT EXISTS user_flows (
    telegram_id INTEGER NOT NULL PRIMARY KEY,
    flow TEXT NOT NULL,
    context TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS uk_rehearsals_template_slot ON rehearsals (template_id, starts_at);

INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 3, '19:00:00', 120, 'Адрес уточни в «Админ: расписание» → шаблоны', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM schedule_templates LIMIT 1);

INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 5, '19:00:00', 120, 'Адрес уточни в «Админ: расписание» → шаблоны', 1, 2
WHERE (SELECT COUNT(*) FROM schedule_templates) = 1;
