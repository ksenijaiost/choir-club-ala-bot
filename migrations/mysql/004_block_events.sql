CREATE TABLE IF NOT EXISTS block_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(16) NOT NULL,
    reason VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_block_events_target (target_user_id, created_at),
    KEY idx_block_events_created (created_at),
    CONSTRAINT fk_block_events_target FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_block_events_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_block_events_action CHECK (action IN ('block', 'unblock')),
    CONSTRAINT chk_block_events_reason CHECK (reason IN ('manual', 'auto'))
);
