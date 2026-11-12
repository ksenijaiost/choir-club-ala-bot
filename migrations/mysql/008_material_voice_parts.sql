CREATE TABLE IF NOT EXISTS material_voice_parts (
    material_id BIGINT UNSIGNED NOT NULL,
    voice_part VARCHAR(32) NOT NULL,
    PRIMARY KEY (material_id, voice_part),
    CONSTRAINT fk_mvp_material FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
);

INSERT IGNORE INTO material_voice_parts (material_id, voice_part)
SELECT id, voice_part
FROM materials
WHERE type = 'recording'
  AND voice_part IS NOT NULL
  AND TRIM(voice_part) <> '';

UPDATE materials
SET voice_part = NULL
WHERE type = 'recording'
  AND voice_part IS NOT NULL;
