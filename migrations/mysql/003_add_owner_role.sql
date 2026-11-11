ALTER TABLE users DROP CHECK chk_users_role;

ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ('member', 'admin', 'conductor', 'owner'));
