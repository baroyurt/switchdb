-- Migration: Create maintenance_windows table
-- Date: 2026-03-17

CREATE TABLE IF NOT EXISTS maintenance_windows (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    device_id    INT          DEFAULT NULL,
    device_name  VARCHAR(100) DEFAULT NULL,
    title        VARCHAR(255) NOT NULL,
    start_time   DATETIME     NOT NULL,
    end_time     DATETIME     NOT NULL,
    recurring    TINYINT(1)   DEFAULT 0,
    recur_days   VARCHAR(20)  DEFAULT NULL,
    recur_start  TIME         DEFAULT NULL,
    recur_end    TIME         DEFAULT NULL,
    suppress_alarms  TINYINT(1) DEFAULT 1,
    created_by   VARCHAR(100) DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mw_device (device_id),
    INDEX idx_mw_times  (start_time, end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
