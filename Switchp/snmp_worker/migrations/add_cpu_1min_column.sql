-- Migration: add cpu_1min column to snmp_devices
-- Run this script against switchdb if the Python migration is not convenient.
--
-- Adds cpu_1min column that caches the 1-minute CPU load average collected
-- by the SNMP worker from C9200L/C9300L switches (OLD-CISCO-CPU-MIB).
-- The ALTER is safe to run multiple times.

USE switchdb;

-- cpu_1min: CPU load percentage averaged over the last 1 minute (0-100)
ALTER TABLE snmp_devices
    ADD COLUMN IF NOT EXISTS cpu_1min INT NULL;

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'switchdb'
  AND TABLE_NAME   = 'snmp_devices'
  AND COLUMN_NAME  = 'cpu_1min';
