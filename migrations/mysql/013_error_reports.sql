CREATE TABLE IF NOT EXISTS error_reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_id BIGINT NOT NULL,
    chat_id BIGINT NOT NULL,
    update_id BIGINT NULL,
    update_type VARCHAR(64) NULL,
    action VARCHAR(200) NULL,
    exception_class VARCHAR(255) NULL,
    exception_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reported_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_error_reports_telegram_reported (telegram_id, reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;