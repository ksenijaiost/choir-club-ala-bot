PRAGMA foreign_keys = OFF;

CREATE TABLE users_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    display_name TEXT NOT NULL DEFAULT '',
    voice_part TEXT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    status TEXT NOT NULL DEFAULT 'pending',
    consecutive_unexcused INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (telegram_id),
    CHECK (role IN ('member', 'admin', 'conductor', 'owner')),
    CHECK (status IN ('pending', 'active', 'blocked', 'rejected')),
    CHECK (
        voice_part IS NULL OR voice_part IN (
            'soprano_1', 'soprano_2', 'alto_1', 'alto_2', 'tenor', 'baritone', 'bass'
        )
    )
);

INSERT INTO users_new (
    id, telegram_id, display_name, voice_part, role, status,
    consecutive_unexcused, created_at, updated_at
)
SELECT
    id, telegram_id, display_name, voice_part, role, status,
    consecutive_unexcused, created_at, updated_at
FROM users;

DROP TABLE users;

ALTER TABLE users_new RENAME TO users;

PRAGMA foreign_keys = ON;
