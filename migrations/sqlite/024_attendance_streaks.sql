ALTER TABLE users ADD COLUMN attendance_streak INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN attendance_streak_best INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS user_streak_awards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    milestone INTEGER NOT NULL,
    awarded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, milestone),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_streak_awards_user ON user_streak_awards (user_id, milestone);