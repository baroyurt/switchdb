-- Migration: add environmental monitoring columns to snmp_devices
-- Run this script against switchdb if the Python migration is not convenient.
--
-- Adds fan_status, temperature_c, poe_nominal_w, poe_consumed_w columns
-- that are required by the SNMP worker's vendor drivers (CBS350, C9200L, C9300L).
-- Each ALTER is wrapped so it is safe to run multiple times.

USE switchdb;

-- fan_status: 'OK' | 'WARNING' | 'CRITICAL' | 'N/A'
ALTER TABLE snmp_devices
    ADD COLUMN IF NOT EXISTS fan_status VARCHAR(20) NULL;

-- temperature_c: CPU/chassis temperature in degrees Celsius
ALTER TABLE snmp_devices
    ADD COLUMN IF NOT EXISTS temperature_c FLOAT NULL;

-- poe_nominal_w: total nominal PoE budget in Watts
ALTER TABLE snmp_devices
    ADD COLUMN IF NOT EXISTS poe_nominal_w INT NULL;

-- poe_consumed_w: current PoE consumption in Watts
ALTER TABLE snmp_devices
    ADD COLUMN IF NOT EXISTS poe_consumed_w INT NULL;

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'switchdb'
  AND TABLE_NAME   = 'snmp_devices'
  AND COLUMN_NAME IN ('fan_status','temperature_c','poe_nominal_w','poe_consumed_w')
ORDER BY ORDINAL_POSITION;
