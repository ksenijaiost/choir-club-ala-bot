CREATE TABLE IF NOT EXISTS program_notify_deliveries (
    program_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    error_text VARCHAR(255) NULL,
    notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (program_id, user_id),
    CONSTRAINT fk_program_notify_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE,
    CONSTRAINT fk_program_notify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_program_notify_deliveries_program ON program_notify_deliveries (program_id);

ALTER TABLE broadcast_recipients MODIFY delivery_error VARCHAR(255) NULL;