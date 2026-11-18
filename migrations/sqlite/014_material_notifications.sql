ALTER TABLE users ADD COLUMN material_notifications_enabled INTEGER NOT NULL DEFAULT 1;

UPDATE users SET material_notifications_enabled = 0 WHERE role = 'conductor';