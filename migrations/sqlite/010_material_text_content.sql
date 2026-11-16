PRAGMA foreign_keys = OFF;

CREATE TABLE materials_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    voice_part TEXT NULL,
    telegram_file_id TEXT NULL,
    telegram_file_unique_id TEXT NULL,
    text_content TEXT NULL,
    is_current INTEGER NOT NULL DEFAULT 1,
    archived_at TEXT NULL,
    uploaded_by_user_id INTEGER NOT NULL,
    uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_chat_message_id INTEGER NULL,
    UNIQUE (telegram_file_unique_id),
    FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CHECK (type IN ('notes', 'translit', 'recording'))
);

INSERT INTO materials_new (
    id, song_id, type, voice_part, telegram_file_id, telegram_file_unique_id, text_content,
    is_current, archived_at, uploaded_by_user_id, uploaded_at, admin_chat_message_id
)
SELECT
    id, song_id, type, voice_part, telegram_file_id, telegram_file_unique_id, NULL,
    is_current, archived_at, uploaded_by_user_id, uploaded_at, admin_chat_message_id
FROM materials;

DROP TABLE materials;

ALTER TABLE materials_new RENAME TO materials;

CREATE INDEX IF NOT EXISTS idx_materials_song ON materials (song_id, type, voice_part, is_current);

PRAGMA foreign_keys = ON;