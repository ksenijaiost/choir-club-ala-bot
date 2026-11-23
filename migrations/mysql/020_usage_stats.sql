CREATE TABLE IF NOT EXISTS usage_daily (
    day DATE NOT NULL,
    section VARCHAR(50) NOT NULL,
    uses INT NOT NULL DEFAULT 0,
    users INT NOT NULL DEFAULT 0,
    PRIMARY KEY (day, section)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_daily_users (
    day DATE NOT NULL,
    section VARCHAR(50) NOT NULL,
    telegram_id BIGINT NOT NULL,
    PRIMARY KEY (day, section, telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_usage_daily_users_day ON usage_daily_users (day);