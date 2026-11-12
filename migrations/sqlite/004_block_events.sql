CREATE TABLE IF NOT EXISTS block_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    target_user_id INTEGER NOT NULL,
    actor_user_id INTEGER NULL,
    action TEXT NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE,
    FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CHECK (action IN ('block', 'unblock')),
    CHECK (reason IN ('manual', 'auto'))
);

CREATE INDEX IF NOT EXISTS idx_block_events_target ON block_events (target_user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_block_events_created ON block_events (created_at);
