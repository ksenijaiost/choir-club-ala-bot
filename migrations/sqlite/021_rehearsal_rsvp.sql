CREATE TABLE IF NOT EXISTS rehearsal_rsvp (
    rehearsal_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    answer TEXT NOT NULL CHECK (answer IN ('yes', 'no')),
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id, user_id),
    FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_rehearsal_rsvp_rehearsal ON rehearsal_rsvp (rehearsal_id);

CREATE TABLE IF NOT EXISTS day_before_reminder_deliveries (
    rehearsal_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('sent', 'failed')),
    error_text TEXT NULL,
    delivered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id, user_id),
    FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_day_before_deliveries_rehearsal ON day_before_reminder_deliveries (rehearsal_id);

CREATE TABLE IF NOT EXISTS rehearsal_rsvp_nudges (
    rehearsal_id INTEGER NOT NULL PRIMARY KEY,
    last_nudged_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE
);