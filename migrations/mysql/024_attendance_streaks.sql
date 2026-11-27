ALTER TABLE users
    ADD COLUMN IF NOT EXISTS attendance_streak INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS attendance_streak_best INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS user_streak_awards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    milestone INT NOT NULL,
    awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_user_streak_awards (user_id, milestone),
    CONSTRAINT fk_user_streak_awards_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;