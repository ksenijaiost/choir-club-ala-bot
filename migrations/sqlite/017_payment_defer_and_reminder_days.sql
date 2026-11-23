ALTER TABLE payment_records ADD COLUMN member_deferred_at TEXT NULL;
ALTER TABLE payment_records ADD COLUMN member_comment TEXT NULL;

UPDATE payment_records
SET period_month = 11
WHERE period_year = 2026
  AND period_month = 12
  AND NOT EXISTS (
    SELECT 1
    FROM payment_records AS pr2
    WHERE pr2.user_id = payment_records.user_id
      AND pr2.period_year = 2026
      AND pr2.period_month = 11
  );

UPDATE bot_settings SET setting_value = '15' WHERE setting_key = 'payment_reminder_day_1';
UPDATE bot_settings SET setting_value = '20' WHERE setting_key = 'payment_reminder_day_2';