CREATE TABLE IF NOT EXISTS programs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL CHECK (length(title) <= 100),
    event_date TEXT NULL,
    is_archived INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at TEXT NULL,
    changed_since_notify INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX idx_programs_archived ON programs (is_archived, event_date);

CREATE TABLE IF NOT EXISTS program_songs (
    program_id INTEGER NOT NULL,
    song_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    PRIMARY KEY (program_id, song_id),
    FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE,
    FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
);

CREATE INDEX idx_program_songs_program ON program_songs (program_id, position);