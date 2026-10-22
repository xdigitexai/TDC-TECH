-- Royalty withdrawal contact email + proof attachment.
-- Idempotent: each ALTER is prepared only when the column is missing.
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE royalty_withdrawals ADD COLUMN email VARCHAR(190) NULL AFTER payout_details',
  'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='royalty_withdrawals' AND COLUMN_NAME='email');
PREPARE m FROM @s; EXECUTE m; DEALLOCATE PREPARE m;

SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE royalty_withdrawals ADD COLUMN proof_media_id VARCHAR(64) NULL AFTER email',
  'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='royalty_withdrawals' AND COLUMN_NAME='proof_media_id');
PREPARE m FROM @s; EXECUTE m; DEALLOCATE PREPARE m;
