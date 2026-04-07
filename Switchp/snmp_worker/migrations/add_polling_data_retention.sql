-- Migration: add_polling_data_retention
-- Date: 2026-03-18
-- Purpose: Prevent unbounded growth of device_polling_data.
--
-- Background
-- ----------
-- The Python SNMP worker inserts one row per device per poll cycle
-- (every ~30 seconds).  With 38 devices that produces ~109,440 rows/day.
-- With no cleanup the table currently holds 419 000+ rows (7 weeks of data)
-- and will grow to millions within months, consuming gigabytes of disk.
--
-- Only recent polling data is valuable for dashboards and trend graphs.
-- Alarms and port changes are stored in dedicated tables, so losing old
-- device_polling_data rows does not affect any alarm or audit history.
--
-- Fix
-- ---
-- 1. Purge rows older than 7 days immediately (one-off cleanup).
-- 2. Create a MySQL EVENT that runs daily and removes rows older than 7 days.
--    Requires event_scheduler = ON (set below; safe to run multiple times).
--
-- Safe to run multiple times (idempotent).

USE switchdb;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 1: One-off purge of rows older than 7 days.
-- ─────────────────────────────────────────────────────────────────────────────
DELETE FROM device_polling_data
WHERE poll_timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 2: Enable the MySQL event scheduler (required for the EVENT below).
--         This is a global setting; SET GLOBAL is only allowed if the DB user
--         has SUPER privilege.  If it fails the DELETE in Step 3 still runs
--         on every update_database.php visit via the PHP step added alongside
--         this migration.
-- ─────────────────────────────────────────────────────────────────────────────
SET GLOBAL event_scheduler = ON;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 3: Create (or replace) a daily event to keep the table lean.
-- ─────────────────────────────────────────────────────────────────────────────
DROP EVENT IF EXISTS evt_purge_device_polling_data;

CREATE EVENT evt_purge_device_polling_data
    ON SCHEDULE EVERY 1 DAY
    STARTS (CURRENT_DATE + INTERVAL 1 DAY)
    ON COMPLETION PRESERVE
    COMMENT 'Delete device_polling_data rows older than 7 days'
DO
    DELETE FROM device_polling_data
    WHERE poll_timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- ─────────────────────────────────────────────────────────────────────────────
-- Verification
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    COUNT(*)                   AS remaining_rows,
    MIN(poll_timestamp)        AS oldest_record,
    MAX(poll_timestamp)        AS newest_record,
    DATEDIFF(MAX(poll_timestamp), MIN(poll_timestamp)) AS span_days
FROM device_polling_data;

SELECT 'add_polling_data_retention migration complete' AS status;
