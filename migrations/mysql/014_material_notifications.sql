ALTER TABLE users ADD COLUMN material_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1;

UPDATE users SET material_notifications_enabled = 0 WHERE role = 'conductor';