CREATE TABLE IF NOT EXISTS programs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(100) NOT NULL,
    event_date DATE NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notified_at DATETIME NULL,
    changed_since_notify TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_programs_archived (is_archived, event_date),
    CONSTRAINT fk_programs_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS program_songs (
    program_id BIGINT UNSIGNED NOT NULL,
    song_id BIGINT UNSIGNED NOT NULL,
    position INT NOT NULL,
    PRIMARY KEY (program_id, song_id),
    KEY idx_program_songs_program (program_id, position),
    CONSTRAINT fk_program_songs_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE,
    CONSTRAINT fk_program_songs_song FOREIGN KEY (song_id) REFERENCES songs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;