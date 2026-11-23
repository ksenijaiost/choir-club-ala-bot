CREATE TABLE IF NOT EXISTS broadcasts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_user_id INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('text', 'poll')),
    text TEXT NOT NULL,
    multi_choice INTEGER NOT NULL DEFAULT 0,
    allow_custom INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at TEXT NULL,
    FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX idx_broadcasts_status_created ON broadcasts (status, created_at DESC);

CREATE TABLE IF NOT EXISTS broadcast_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    broadcast_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    label TEXT NOT NULL,
    FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE
);

CREATE INDEX idx_broadcast_options_broadcast ON broadcast_options (broadcast_id, position);

CREATE TABLE IF NOT EXISTS broadcast_recipients (
    broadcast_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    telegram_message_id INTEGER NULL,
    delivered_at TEXT NULL,
    delivery_error TEXT NULL,
    read_at TEXT NULL,
    PRIMARY KEY (broadcast_id, user_id),
    FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS broadcast_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    broadcast_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    option_id INTEGER NULL,
    custom_text TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES broadcast_options (id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uk_broadcast_answers_option
    ON broadcast_answers (broadcast_id, user_id, option_id)
    WHERE option_id IS NOT NULL;

CREATE UNIQUE INDEX uk_broadcast_answers_custom
    ON broadcast_answers (broadcast_id, user_id)
    WHERE option_id IS NULL;