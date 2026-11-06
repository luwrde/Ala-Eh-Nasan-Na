CREATE TABLE IF NOT EXISTS app_sessions (
    session_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_data MEDIUMBLOB NOT NULL,
    expires_at BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (session_id),
    KEY idx_app_sessions_expires_at (expires_at)
) ENGINE=InnoDB;

ALTER TABLE driver_documents
    MODIFY storage_key CHAR(64) NULL;
