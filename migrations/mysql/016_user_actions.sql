CREATE TABLE IF NOT EXISTS user_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    update_type VARCHAR(32) NULL,
    action VARCHAR(200) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_user_actions_telegram_created (telegram_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;