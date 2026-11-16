ALTER TABLE materials ADD COLUMN text_content TEXT NULL;
ALTER TABLE materials MODIFY telegram_file_id VARCHAR(512) NULL;
ALTER TABLE materials MODIFY telegram_file_unique_id VARCHAR(128) NULL;