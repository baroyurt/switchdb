-- Migration: Fix acknowledged_port_mac table schema
-- Purpose: The base schema (schema_base.sql) created this table with a device_id
--          column, but all PHP and Python code queries by device_name.  The later
--          migration (add_acknowledged_port_mac_table.sql) used CREATE TABLE IF NOT
--          EXISTS, so it silently skipped the fix on existing installs, leaving the
--          table with the wrong schema and breaking the entire whitelist mechanism.
--
-- This migration is idempotent: it checks before altering so it is safe to run
-- multiple times or on databases that already have the correct schema.
-- Date: 2026-03-17

-- Step 1: Add device_name column if it does not already exist.
--         The column is initially nullable so we can populate it before making it NOT NULL.
ALTER TABLE acknowledged_port_mac
    ADD COLUMN IF NOT EXISTS device_name VARCHAR(100) DEFAULT NULL
        AFTER id;

-- Step 2: Set a safe placeholder for any rows that still lack device_name.
--         On systems where the old schema (device_id) was used, all rows will
--         get 'UNKNOWN' here; the PHP guard step in update_database.php will
--         then re-populate proper device names via a JOIN when device_id exists.
--         On systems where the table was always correct (device_name present),
--         all rows already have a value so this UPDATE affects 0 rows.
--         NOTE: We intentionally do NOT reference apm.device_id here because
--         that column may not exist on newer installations, which would cause
--         MySQL to abort with "Unknown column 'apm.device_id' in 'on clause'".
UPDATE acknowledged_port_mac
SET device_name = 'UNKNOWN'
WHERE device_name IS NULL;

-- Step 4: Make device_name NOT NULL now that every row has a value.
ALTER TABLE acknowledged_port_mac
    MODIFY COLUMN device_name VARCHAR(100) NOT NULL;

-- Step 5: Add note column (present in migration version, absent in base-schema version).
ALTER TABLE acknowledged_port_mac
    ADD COLUMN IF NOT EXISTS note TEXT DEFAULT NULL;

-- Step 6: Add updated_at column (present in migration version, absent in base-schema version).
ALTER TABLE acknowledged_port_mac
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Step 7: Drop the old device_id-based unique key if it exists.
--         The key name in schema_base.sql was 'uq_ack_port_mac'.
--         We use ALTER TABLE … DROP INDEX only when the key actually exists;
--         the IF EXISTS syntax avoids errors on databases that already migrated.
ALTER TABLE acknowledged_port_mac
    DROP INDEX IF EXISTS uq_ack_port_mac;

-- Step 8: Drop the old device_id-based device index if it exists.
ALTER TABLE acknowledged_port_mac
    DROP INDEX IF EXISTS idx_ack_port_mac_device;

-- Step 9: Add the correct unique key on (device_name, port_number, mac_address)
--         if it does not already exist.
ALTER TABLE acknowledged_port_mac
    ADD UNIQUE KEY IF NOT EXISTS unique_whitelist (device_name, port_number, mac_address);

-- Step 10: Add performance indexes if they do not already exist.
ALTER TABLE acknowledged_port_mac
    ADD INDEX IF NOT EXISTS idx_device_port (device_name, port_number);

ALTER TABLE acknowledged_port_mac
    ADD INDEX IF NOT EXISTS idx_mac (mac_address);

-- Migration complete
