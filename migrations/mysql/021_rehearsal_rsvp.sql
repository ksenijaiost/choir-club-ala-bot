CREATE TABLE IF NOT EXISTS rehearsal_rsvp (
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    answer VARCHAR(8) NOT NULL,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id, user_id),
    CONSTRAINT fk_rehearsal_rsvp_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    CONSTRAINT fk_rehearsal_rsvp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_rehearsal_rsvp_answer CHECK (answer IN ('yes', 'no'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS day_before_reminder_deliveries (
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL,
    error_text TEXT NULL,
    delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id, user_id),
    CONSTRAINT fk_day_before_delivery_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    CONSTRAINT fk_day_before_delivery_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_day_before_delivery_status CHECK (status IN ('sent', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rehearsal_rsvp_nudges (
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    last_nudged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id),
    CONSTRAINT fk_rehearsal_rsvp_nudges_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;