-- MySQL initial schema (mirrors docs/schema.sql)

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    telegram_id BIGINT NOT NULL,
    display_name VARCHAR(255) NOT NULL DEFAULT '',
    voice_part VARCHAR(32) NOT NULL,
    role VARCHAR(16) NOT NULL DEFAULT 'member',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    consecutive_unexcused INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_telegram_id (telegram_id),
    CONSTRAINT chk_users_role CHECK (role IN ('member', 'helper', 'conductor')),
    CONSTRAINT chk_users_status CHECK (status IN ('pending', 'active', 'blocked', 'rejected')),
    CONSTRAINT chk_users_voice_part CHECK (voice_part IN (
        'soprano_1', 'soprano_2', 'alto_1', 'alto_2', 'tenor', 'baritone', 'bass'
    ))
);

CREATE TABLE IF NOT EXISTS registration_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    admin_message_id BIGINT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_reg_req_user (user_id),
    CONSTRAINT fk_reg_req_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_reg_req_status CHECK (status IN ('pending', 'accepted', 'rejected'))
);

CREATE TABLE IF NOT EXISTS schedule_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    weekday TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    duration_minutes INT NOT NULL DEFAULT 120,
    address VARCHAR(512) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT chk_schedule_weekday CHECK (weekday BETWEEN 1 AND 7)
);

CREATE TABLE IF NOT EXISTS rehearsals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    address VARCHAR(512) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rehearsals_starts (starts_at, status),
    CONSTRAINT fk_rehearsals_template FOREIGN KEY (template_id) REFERENCES schedule_templates (id) ON DELETE SET NULL,
    CONSTRAINT chk_rehearsals_status CHECK (status IN ('scheduled', 'cancelled', 'completed'))
);

CREATE TABLE IF NOT EXISTS attendance_marks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    mark VARCHAR(16) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'system',
    reason_text TEXT NULL,
    marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_attendance_rehearsal_user (rehearsal_id, user_id),
    KEY idx_attendance_user (user_id, marked_at),
    CONSTRAINT fk_attendance_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_attendance_mark CHECK (mark IN ('attended', 'warned', 'unexcused', 'excused_manual'))
);

CREATE TABLE IF NOT EXISTS check_in_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_check_in_rehearsal (rehearsal_id),
    CONSTRAINT fk_check_in_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payment_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    period_year SMALLINT NOT NULL,
    period_month TINYINT UNSIGNED NOT NULL,
    member_claimed_at DATETIME NULL,
    conductor_confirmed_at DATETIME NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_payment_user_period (user_id, period_year, period_month),
    CONSTRAINT fk_payment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_confirmer FOREIGN KEY (confirmed_by_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_payment_month CHECK (period_month BETWEEN 1 AND 12)
);

CREATE TABLE IF NOT EXISTS songs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    title_normalized VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_songs_title_norm (title_normalized)
);

CREATE TABLE IF NOT EXISTS materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(16) NOT NULL,
    voice_part VARCHAR(32) NULL,
    telegram_file_id VARCHAR(512) NOT NULL,
    telegram_file_unique_id VARCHAR(128) NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    admin_chat_message_id BIGINT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_materials_file_unique (telegram_file_unique_id),
    KEY idx_materials_song (song_id, type, voice_part, is_current),
    CONSTRAINT fk_materials_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE,
    CONSTRAINT fk_materials_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_materials_type CHECK (type IN ('notes', 'translit', 'recording'))
);

CREATE TABLE IF NOT EXISTS bot_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT NOT NULL,
    PRIMARY KEY (setting_key)
);

CREATE TABLE IF NOT EXISTS job_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_key VARCHAR(191) NOT NULL,
    job_type VARCHAR(64) NOT NULL,
    related_id BIGINT UNSIGNED NULL,
    delivered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    payload_hash VARCHAR(64) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_job_deliveries_key (job_key),
    KEY idx_job_deliveries_type (job_type, delivered_at)
);

INSERT INTO bot_settings (setting_key, setting_value) VALUES
    ('payment_reminder_day_1', '23'),
    ('payment_reminder_day_2', '1'),
    ('payment_reminder_hour', '12'),
    ('schedule_generation_weeks', '4'),
    ('rehearsal_default_duration_minutes', '120')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
