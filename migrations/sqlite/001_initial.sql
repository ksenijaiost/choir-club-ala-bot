PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    display_name TEXT NOT NULL DEFAULT '',
    voice_part TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    status TEXT NOT NULL DEFAULT 'pending',
    consecutive_unexcused INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (telegram_id),
    CHECK (role IN ('member', 'helper', 'conductor')),
    CHECK (status IN ('pending', 'active', 'blocked', 'rejected')),
    CHECK (voice_part IN ('soprano_1', 'soprano_2', 'alto_1', 'alto_2', 'tenor', 'baritone', 'bass'))
);

CREATE TABLE IF NOT EXISTS registration_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    admin_message_id INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CHECK (status IN ('pending', 'accepted', 'rejected'))
);

CREATE INDEX IF NOT EXISTS idx_reg_req_user ON registration_requests (user_id);

CREATE TABLE IF NOT EXISTS schedule_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    weekday INTEGER NOT NULL,
    start_time TEXT NOT NULL,
    duration_minutes INTEGER NOT NULL DEFAULT 120,
    address TEXT NOT NULL DEFAULT '',
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    CHECK (weekday BETWEEN 1 AND 7)
);

CREATE TABLE IF NOT EXISTS rehearsals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NULL,
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    address TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'scheduled',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES schedule_templates (id) ON DELETE SET NULL,
    CHECK (status IN ('scheduled', 'cancelled', 'completed'))
);

CREATE INDEX IF NOT EXISTS idx_rehearsals_starts ON rehearsals (starts_at, status);

CREATE TABLE IF NOT EXISTS attendance_marks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rehearsal_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    mark TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'system',
    reason_text TEXT NULL,
    marked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (rehearsal_id, user_id),
    FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CHECK (mark IN ('attended', 'warned', 'unexcused', 'excused_manual'))
);

CREATE INDEX IF NOT EXISTS idx_attendance_user ON attendance_marks (user_id, marked_at);

CREATE TABLE IF NOT EXISTS check_in_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rehearsal_id INTEGER NOT NULL,
    started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TEXT NULL,
    UNIQUE (rehearsal_id),
    FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payment_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    period_year INTEGER NOT NULL,
    period_month INTEGER NOT NULL,
    member_claimed_at TEXT NULL,
    conductor_confirmed_at TEXT NULL,
    confirmed_by_user_id INTEGER NULL,
    UNIQUE (user_id, period_year, period_month),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CHECK (period_month BETWEEN 1 AND 12)
);

CREATE TABLE IF NOT EXISTS songs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    title_normalized TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_songs_title_norm ON songs (title_normalized);

CREATE TABLE IF NOT EXISTS materials (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    song_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    voice_part TEXT NULL,
    telegram_file_id TEXT NOT NULL,
    telegram_file_unique_id TEXT NOT NULL,
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

CREATE INDEX IF NOT EXISTS idx_materials_song ON materials (song_id, type, voice_part, is_current);

CREATE TABLE IF NOT EXISTS bot_settings (
    setting_key TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS job_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_key TEXT NOT NULL,
    job_type TEXT NOT NULL,
    related_id INTEGER NULL,
    delivered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_hash TEXT NULL,
    UNIQUE (job_key)
);

CREATE INDEX IF NOT EXISTS idx_job_deliveries_type ON job_deliveries (job_type, delivered_at);

INSERT OR IGNORE INTO bot_settings (setting_key, setting_value) VALUES
    ('payment_reminder_day_1', '23'),
    ('payment_reminder_day_2', '1'),
    ('payment_reminder_hour', '12'),
    ('schedule_generation_weeks', '4'),
    ('rehearsal_default_duration_minutes', '120');
