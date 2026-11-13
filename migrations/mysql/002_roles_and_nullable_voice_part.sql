ALTER TABLE users DROP CONSTRAINT chk_users_voice_part;

ALTER TABLE users MODIFY voice_part VARCHAR(32) NULL;

ALTER TABLE users DROP CONSTRAINT chk_users_role;

UPDATE users SET role = 'admin' WHERE role = 'helper';

ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('member', 'admin', 'conductor'));
