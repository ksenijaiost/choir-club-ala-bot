UPDATE users
SET consecutive_unexcused = 0,
    updated_at = CURRENT_TIMESTAMP
WHERE role = 'conductor';