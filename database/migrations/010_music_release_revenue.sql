-- Per-release revenue and delivery status for the Royalties & Revenue page.
-- Idempotent: each ALTER is prepared only when the column is missing.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE music_release_reports ADD COLUMN revenue DECIMAL(10,2) NOT NULL DEFAULT 0.00',
  'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='music_release_reports' AND COLUMN_NAME='revenue');
PREPARE m FROM @s; EXECUTE m; DEALLOCATE PREPARE m;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE music_release_reports ADD COLUMN release_status VARCHAR(20) NOT NULL DEFAULT ''pending''',
  'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='music_release_reports' AND COLUMN_NAME='release_status');
PREPARE m FROM @s; EXECUTE m; DEALLOCATE PREPARE m;
