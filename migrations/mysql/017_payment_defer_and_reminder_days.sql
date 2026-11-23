ALTER TABLE payment_records
    ADD COLUMN member_deferred_at DATETIME NULL,
    ADD COLUMN member_comment VARCHAR(500) NULL;

UPDATE payment_records AS pr
LEFT JOIN payment_records AS nov
    ON nov.user_id = pr.user_id AND nov.period_year = 2026 AND nov.period_month = 11
SET pr.period_month = 11
WHERE pr.period_year = 2026
  AND pr.period_month = 12
  AND nov.user_id IS NULL;

UPDATE bot_settings SET setting_value = '15' WHERE setting_key = 'payment_reminder_day_1';
UPDATE bot_settings SET setting_value = '20' WHERE setting_key = 'payment_reminder_day_2';