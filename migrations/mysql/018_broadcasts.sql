CREATE TABLE IF NOT EXISTS broadcasts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    author_user_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(8) NOT NULL,
    text TEXT NOT NULL,
    multi_choice TINYINT(1) NOT NULL DEFAULT 0,
    allow_custom TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(8) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_broadcasts_status_created (status, created_at),
    CONSTRAINT fk_broadcasts_author FOREIGN KEY (author_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_broadcasts_kind CHECK (kind IN ('text', 'poll')),
    CONSTRAINT chk_broadcasts_status CHECK (status IN ('open', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS broadcast_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broadcast_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    label VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_broadcast_options_broadcast (broadcast_id, position),
    CONSTRAINT fk_broadcast_options_broadcast FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS broadcast_recipients (
    broadcast_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    telegram_message_id BIGINT UNSIGNED NULL,
    delivered_at DATETIME NULL,
    delivery_error VARCHAR(16) NULL,
    read_at DATETIME NULL,
    PRIMARY KEY (broadcast_id, user_id),
    CONSTRAINT fk_broadcast_recipients_broadcast FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE,
    CONSTRAINT fk_broadcast_recipients_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS broadcast_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    broadcast_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    option_id BIGINT UNSIGNED NULL,
    custom_text VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_broadcast_answers_option (broadcast_id, user_id, option_id),
    KEY idx_broadcast_answers_custom (broadcast_id, user_id),
    CONSTRAINT fk_broadcast_answers_broadcast FOREIGN KEY (broadcast_id) REFERENCES broadcasts (id) ON DELETE CASCADE,
    CONSTRAINT fk_broadcast_answers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_broadcast_answers_option FOREIGN KEY (option_id) REFERENCES broadcast_options (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;