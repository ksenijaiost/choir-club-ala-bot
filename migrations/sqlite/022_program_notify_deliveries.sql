CREATE TABLE IF NOT EXISTS program_notify_deliveries (
    program_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('sent', 'failed')),
    error_text TEXT NULL,
    notified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id, user_id),
    FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX idx_program_notify_deliveries_program ON program_notify_deliveries (program_id);