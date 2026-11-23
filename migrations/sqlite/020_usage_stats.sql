CREATE TABLE IF NOT EXISTS usage_daily (
    day TEXT NOT NULL,
    section VARCHAR(50) NOT NULL,
    uses INTEGER NOT NULL DEFAULT 0,
    users INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (day, section)
);

CREATE TABLE IF NOT EXISTS usage_daily_users (
    day TEXT NOT NULL,
    section VARCHAR(50) NOT NULL,
    telegram_id INTEGER NOT NULL,
    PRIMARY KEY (day, section, telegram_id)
);

CREATE INDEX IF NOT EXISTS idx_usage_daily_users_day ON usage_daily_users (day);