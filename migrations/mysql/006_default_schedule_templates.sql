UPDATE schedule_templates
SET start_time = '19:30:00',
    duration_minutes = 120,
    address = 'комьюнити-центр'
WHERE weekday = 3;

UPDATE schedule_templates
SET weekday = 6,
    start_time = '16:00:00',
    duration_minutes = 120,
    address = 'комьюнити-центр'
WHERE weekday = 5;

INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 3, '19:30:00', 120, 'комьюнити-центр', 1, 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM schedule_templates WHERE weekday = 3);

INSERT INTO schedule_templates (weekday, start_time, duration_minutes, address, is_active, sort_order)
SELECT 6, '16:00:00', 120, 'комьюнити-центр', 1, 2
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM schedule_templates WHERE weekday = 6);

DELETE FROM rehearsals
WHERE status = 'scheduled'
  AND template_id IS NOT NULL
  AND starts_at >= NOW();
