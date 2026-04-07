-- Migration: Add 'snmp_hub_auto' to mac_device_registry.source ENUM
-- Purpose: Allow SNMP worker to mark auto-registered hub-port MACs with a
--          dedicated source value so user-entered/Excel data is never overwritten.
-- Date: 2026-03-08

-- Extend ENUM to include 'snmp_hub_auto'
ALTER TABLE mac_device_registry
    MODIFY COLUMN source ENUM('domain', 'excel', 'manual', 'snmp_hub_auto') DEFAULT 'manual';
