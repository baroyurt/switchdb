-- ============================================================================
-- Add Port Reservation Columns Migration
-- Date: 2026-07-01
-- Purpose: Add is_reserved and reserved_for columns to the ports table
-- ============================================================================
--
-- This migration adds two columns to support Port Reservation (Port Rezervasyonu):
--   - is_reserved: flag indicating whether the port is reserved (0/1)
--   - reserved_for: free-text description of who/what the port is reserved for
--
-- Reserved ports are shown with a purple border and REZERVE label in the UI.
-- ============================================================================

USE switchdb;

-- Add is_reserved column if it doesn't exist
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'switchdb'
    AND TABLE_NAME = 'ports'
    AND COLUMN_NAME = 'is_reserved'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE ports ADD COLUMN is_reserved TINYINT(1) DEFAULT 0 COMMENT ''Port rezerve mi?'' AFTER connected_to',
    'SELECT ''Column is_reserved already exists'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add reserved_for column if it doesn't exist
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'switchdb'
    AND TABLE_NAME = 'ports'
    AND COLUMN_NAME = 'reserved_for'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE ports ADD COLUMN reserved_for VARCHAR(255) DEFAULT NULL COMMENT ''Kim için rezerve?'' AFTER is_reserved',
    'SELECT ''Column reserved_for already exists'' AS status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify columns were added
SELECT 'SUCCESS: Port reservation columns migration complete' AS status;

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'switchdb'
AND TABLE_NAME = 'ports'
AND COLUMN_NAME IN ('is_reserved', 'reserved_for')
ORDER BY COLUMN_NAME;
