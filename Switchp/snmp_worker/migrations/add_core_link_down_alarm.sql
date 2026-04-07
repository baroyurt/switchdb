-- Add core_link_down alarm type to alarm_severity_config.
-- This alarm fires when a core uplink port (defined in snmp_core_ports) goes down.
-- Existing installations that ran create_alarm_severity_config.sql before this
-- entry was added need this standalone migration to populate the row.

INSERT INTO alarm_severity_config (alarm_type, severity, telegram_enabled, email_enabled, description)
VALUES ('core_link_down', 'CRITICAL', TRUE, TRUE, 'Core uplink bağlantısı kesildi')
ON DUPLICATE KEY UPDATE
    severity = VALUES(severity),
    telegram_enabled = VALUES(telegram_enabled),
    email_enabled = VALUES(email_enabled),
    description = VALUES(description);
