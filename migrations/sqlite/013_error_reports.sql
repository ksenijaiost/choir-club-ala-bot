CREATE TABLE IF NOT EXISTS error_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL,
    chat_id INTEGER NOT NULL,
    update_id INTEGER,
    update_type TEXT,
    action TEXT,
    exception_class TEXT,
    exception_message TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reported_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_error_reports_telegram_reported
    ON error_reports (telegram_id, reported_at);