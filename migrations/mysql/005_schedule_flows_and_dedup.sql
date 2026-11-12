CREATE TABLE IF NOT EXISTS user_flows (
    telegram_id BIGINT NOT NULL,
    flow VARCHAR(32) NOT NULL,
    context TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (telegram_id)
);

CREATE UNIQUE INDEX uk_rehearsals_template_slot
    ON rehearsals (template_id, starts_at);

-- Шаблон по умолчанию (2 репетиции), если таблица пуста
INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 3, '19:00:00', 120, 'Адрес уточни в «Админ: расписание» → шаблоны', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM schedule_templates LIMIT 1);

INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 5, '19:00:00', 120, 'Адрес уточни в «Админ: расписание» → шаблоны', 1, 2
WHERE (SELECT COUNT(*) FROM schedule_templates) = 1;
