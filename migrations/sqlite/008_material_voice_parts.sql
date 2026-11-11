CREATE TABLE IF NOT EXISTS material_voice_parts (
    material_id INTEGER NOT NULL,
    voice_part TEXT NOT NULL,
    PRIMARY KEY (material_id, voice_part),
    FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO material_voice_parts (material_id, voice_part)
SELECT id, voice_part
FROM materials
WHERE type = 'recording'
  AND voice_part IS NOT NULL
  AND trim(voice_part) != '';

UPDATE materials
SET voice_part = NULL
WHERE type = 'recording'
  AND voice_part IS NOT NULL;
