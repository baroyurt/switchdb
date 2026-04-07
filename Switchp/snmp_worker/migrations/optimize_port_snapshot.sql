-- ============================================================================
-- Migration: optimize_port_snapshot
-- Date: 2026-03-16
-- Purpose: Convert port_snapshot from an append-only history table to a
--          single-row-per-port "current state" table.
--
-- Background
-- ----------
-- The Python SNMP worker previously INSERT-ed a new port_snapshot row on
-- EVERY poll cycle (every 30 s by default).  With hundreds of ports this
-- results in millions of rows per day (observed: 2.4 GiB / 16.8 M rows).
--
-- Only the MOST RECENT row per (device_id, port_number) is ever read by the
-- change-detection logic.  Historical snapshots are redundant because actual
-- changes are logged in port_change_history.
--
-- Fix
-- ---
-- 1. Delete all duplicate rows — keep only the latest row per port.
-- 2. Add UNIQUE KEY uq_ps_device_port (device_id, port_number) so that the
--    worker can use INSERT … ON DUPLICATE KEY UPDATE (UPSERT) going forward.
-- 3. Drop the old composite index idx_device_port_time / idx_ps_device_port_time
--    (superseded by the unique key).
--
-- Safe to run multiple times (idempotent via IF EXISTS guards).
-- ============================================================================

USE switchdb;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 1: Remove duplicate rows, keeping only the latest per device+port.
--
-- We do this in a single DELETE … JOIN to avoid a temporary-table lock on the
-- whole table.  The sub-query finds the max id (= latest insert) per pair and
-- the DELETE removes every other row.
-- ─────────────────────────────────────────────────────────────────────────────
DELETE ps
FROM port_snapshot ps
INNER JOIN (
    SELECT device_id, port_number, MAX(id) AS keep_id
    FROM port_snapshot
    GROUP BY device_id, port_number
) latest
    ON  ps.device_id   = latest.device_id
    AND ps.port_number = latest.port_number
    AND ps.id         <  latest.keep_id;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 2: Trim any remaining rows older than 15 days.
--         (After step 1 this is at most one orphan row per port for devices
--          that have been decommissioned.)
-- ─────────────────────────────────────────────────────────────────────────────
DELETE FROM port_snapshot
WHERE snapshot_timestamp < DATE_SUB(NOW(), INTERVAL 15 DAY);

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 3: Add the unique key that enables UPSERT.
--         Guarded so re-running the migration is safe.
-- ─────────────────────────────────────────────────────────────────────────────
SET @have_uq = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'port_snapshot'
      AND INDEX_NAME   = 'uq_ps_device_port'
);

SET @add_uq = IF(
    @have_uq = 0,
    'ALTER TABLE port_snapshot ADD UNIQUE KEY uq_ps_device_port (device_id, port_number)',
    'SELECT ''uq_ps_device_port already exists'' AS info'
);

PREPARE _stmt FROM @add_uq;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- ─────────────────────────────────────────────────────────────────────────────
-- Step 4: Drop the old idx_device_port_time / idx_ps_device_port_time index
--         (superseded by the unique key above).
-- ─────────────────────────────────────────────────────────────────────────────
SET @drop_old1 = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'port_snapshot'
      AND INDEX_NAME   = 'idx_device_port_time'
);
SET @stmt1 = IF(
    @drop_old1 > 0,
    'ALTER TABLE port_snapshot DROP INDEX idx_device_port_time',
    'SELECT ''idx_device_port_time not present'' AS info'
);
PREPARE _s FROM @stmt1; EXECUTE _s; DEALLOCATE PREPARE _s;

SET @drop_old2 = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'port_snapshot'
      AND INDEX_NAME   = 'idx_ps_device_port_time'
);
SET @stmt2 = IF(
    @drop_old2 > 0,
    'ALTER TABLE port_snapshot DROP INDEX idx_ps_device_port_time',
    'SELECT ''idx_ps_device_port_time not present'' AS info'
);
PREPARE _s FROM @stmt2; EXECUTE _s; DEALLOCATE PREPARE _s;

-- ─────────────────────────────────────────────────────────────────────────────
-- Verification
-- ─────────────────────────────────────────────────────────────────────────────
SELECT
    COUNT(*)                                          AS remaining_rows,
    COUNT(DISTINCT device_id, port_number)            AS unique_device_ports,
    MIN(snapshot_timestamp)                           AS oldest_snapshot,
    MAX(snapshot_timestamp)                           AS newest_snapshot
FROM port_snapshot;

SELECT 'optimize_port_snapshot migration complete' AS status;
