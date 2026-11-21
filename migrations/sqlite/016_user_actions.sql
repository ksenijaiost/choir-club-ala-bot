CREATE TABLE IF NOT EXISTS user_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_type TEXT,
    action TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_user_actions_telegram_created
    ON user_actions (telegram_id, created_at);