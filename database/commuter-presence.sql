-- Add presence tracking to existing commuter accounts without affecting account access status.
SET @commuter_presence_column_exists := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'commuter_accounts'
      AND column_name = 'last_activity_at'
);
SET @commuter_presence_migration := IF(
    @commuter_presence_column_exists = 0,
    'ALTER TABLE commuter_accounts ADD COLUMN last_activity_at TIMESTAMP NULL DEFAULT NULL AFTER password_hash',
    'DO 0'
);
PREPARE commuter_presence_statement FROM @commuter_presence_migration;
EXECUTE commuter_presence_statement;
DEALLOCATE PREPARE commuter_presence_statement;
