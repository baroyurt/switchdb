-- Migration: Create port_traffic_history table
-- Date: 2026-03-17
-- Purpose: Store periodic traffic samples for charts and trend analysis

CREATE TABLE IF NOT EXISTS port_traffic_history (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_id    INT          NOT NULL,
    port_number  INT          NOT NULL,
    sample_time  DATETIME     NOT NULL,
    in_octets    BIGINT       DEFAULT 0,
    out_octets   BIGINT       DEFAULT 0,
    in_bps       BIGINT       DEFAULT NULL,
    out_bps      BIGINT       DEFAULT NULL,
    INDEX idx_pth_device_port (device_id, port_number),
    INDEX idx_pth_time        (sample_time),
    INDEX idx_pth_device_port_time (device_id, port_number, sample_time),
    FOREIGN KEY (device_id) REFERENCES snmp_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auto-cleanup: Keep only last 7 days (can be run as event or via Python cleanup)
-- DELETE FROM port_traffic_history WHERE sample_time < DATE_SUB(NOW(), INTERVAL 7 DAY);
