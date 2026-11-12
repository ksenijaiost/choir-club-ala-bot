CREATE TABLE IF NOT EXISTS check_in_prompts (
    rehearsal_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    chat_id BIGINT NOT NULL,
    message_id BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rehearsal_id, user_id),
    CONSTRAINT fk_check_in_prompt_rehearsal FOREIGN KEY (rehearsal_id) REFERENCES rehearsals (id) ON DELETE CASCADE,
    CONSTRAINT fk_check_in_prompt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);

CREATE INDEX idx_check_in_prompts_rehearsal ON check_in_prompts (rehearsal_id);
