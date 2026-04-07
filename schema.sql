-- =============================================================
--  switchdb  --  Fresh Installation Schema
--  Generated from: switchdb.sql (structure only, no user data)
--  Usage:
--    mysql -u root -p < schema.sql
--  Or in MySQL shell:
--    source C:/path/to/schema.sql
-- =============================================================

CREATE DATABASE IF NOT EXISTS `switchdb`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `switchdb`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


-- --------------------------------------------------------

--
-- Table structure for table `acknowledged_port_mac`
--

CREATE TABLE `acknowledged_port_mac` (
  `id` int(11) NOT NULL,
  `device_name` varchar(100) NOT NULL,
  `port_number` int(11) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `acknowledged_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acknowledged_by` varchar(100) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `alarms`
--

CREATE TABLE `alarms` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `alarm_type` varchar(50) NOT NULL,
  `severity` enum('CRITICAL','HIGH','MEDIUM','LOW','INFO') DEFAULT 'MEDIUM',
  `status` enum('ACTIVE','ACKNOWLEDGED','RESOLVED') DEFAULT 'ACTIVE',
  `port_number` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `details` longtext DEFAULT NULL,
  `occurrence_count` int(11) DEFAULT 1,
  `first_occurrence` datetime NOT NULL,
  `last_occurrence` datetime NOT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledged_by` varchar(100) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notification_sent` tinyint(1) NOT NULL DEFAULT 0,
  `last_notification_sent` datetime DEFAULT NULL,
  `acknowledgment_type` enum('known_change','silenced','resolved') DEFAULT NULL,
  `silence_until` datetime DEFAULT NULL,
  `is_silenced` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether alarm is currently silenced (0=active, 1=silenced)',
  `mac_address` varchar(17) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `from_port` int(11) DEFAULT NULL,
  `to_port` int(11) DEFAULT NULL,
  `old_vlan_id` int(11) DEFAULT NULL,
  `new_vlan_id` int(11) DEFAULT NULL,
  `alarm_fingerprint` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `alarm_history`
--

CREATE TABLE `alarm_history` (
  `id` int(11) NOT NULL,
  `alarm_id` int(11) NOT NULL,
  `old_status` enum('ACTIVE','ACKNOWLEDGED','RESOLVED') DEFAULT NULL,
  `new_status` enum('ACTIVE','ACKNOWLEDGED','RESOLVED') NOT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `change_message` text DEFAULT NULL,
  `changed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `alarm_severity_config`
--

CREATE TABLE `alarm_severity_config` (
  `id` int(11) NOT NULL,
  `alarm_type` varchar(50) NOT NULL,
  `severity` enum('CRITICAL','HIGH','MEDIUM','LOW') NOT NULL DEFAULT 'MEDIUM',
  `telegram_enabled` tinyint(1) DEFAULT 1,
  `email_enabled` tinyint(1) DEFAULT 1,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `connection_history`
--

CREATE TABLE `connection_history` (
  `id` int(11) NOT NULL,
  `connection_type` enum('switch_to_patch','switch_to_fiber','fiber_to_fiber') NOT NULL,
  `source_type` varchar(50) NOT NULL,
  `source_id` int(11) NOT NULL,
  `source_port` int(11) NOT NULL,
  `target_type` varchar(50) NOT NULL,
  `target_id` int(11) NOT NULL,
  `target_port` int(11) NOT NULL,
  `action` enum('created','updated','deleted') NOT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_name` varchar(255) DEFAULT 'system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `device_polling_data`
--

CREATE TABLE `device_polling_data` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `poll_timestamp` datetime NOT NULL,
  `system_name` varchar(255) DEFAULT NULL,
  `system_description` text DEFAULT NULL,
  `system_uptime` bigint(20) DEFAULT NULL,
  `system_contact` varchar(255) DEFAULT NULL,
  `system_location` varchar(255) DEFAULT NULL,
  `total_ports` int(11) DEFAULT 0,
  `active_ports` int(11) DEFAULT 0,
  `cpu_usage` float DEFAULT NULL,
  `memory_usage` float DEFAULT NULL,
  `temperature` float DEFAULT NULL,
  `raw_data` longtext DEFAULT NULL,
  `poll_duration_ms` float DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `error_message` text DEFAULT NULL,
  `uptime_seconds` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Stand-in structure for view `failed_migrations`
-- (See below for the actual view)
--
CREATE TABLE `failed_migrations` (
`id` int(11)
,`migration_name` varchar(255)
,`migration_type` enum('SQL','PYTHON','PHP')
,`applied_at` timestamp
,`error_message` text
,`execution_time_ms` int(11)
);

-- --------------------------------------------------------

--
-- Table structure for table `fiber_panels`
--

CREATE TABLE `fiber_panels` (
  `id` int(11) NOT NULL,
  `rack_id` int(11) NOT NULL,
  `panel_letter` varchar(1) NOT NULL,
  `total_fibers` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `position_in_rack` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `fiber_ports`
--

CREATE TABLE `fiber_ports` (
  `id` int(11) NOT NULL,
  `panel_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'inactive',
  `connected_to` varchar(255) DEFAULT NULL,
  `connection_type` enum('switch_fiber','panel_to_panel','jump_point','switch_to_panel','panel_to_switch') DEFAULT 'switch_fiber',
  `connected_switch_id` int(11) DEFAULT NULL,
  `connected_switch_port` int(11) DEFAULT NULL,
  `connected_fiber_panel_id` int(11) DEFAULT NULL,
  `connected_fiber_panel_port` int(11) DEFAULT NULL,
  `is_jump_point` tinyint(1) DEFAULT 0,
  `jump_path` text DEFAULT NULL,
  `connection_details` text DEFAULT NULL,
  `connection_info_preserved` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `sync_version` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hub_sw_port_connections`
--

CREATE TABLE `hub_sw_port_connections` (
  `id` int(11) NOT NULL,
  `rack_device_id` int(11) NOT NULL,
  `port_number` smallint(6) NOT NULL,
  `conn_type` varchar(30) NOT NULL DEFAULT 'device',
  `device_name` varchar(255) DEFAULT NULL,
  `switch_id` int(11) DEFAULT NULL,
  `switch_port` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `mac_address_tracking`
--

CREATE TABLE `mac_address_tracking` (
  `id` int(11) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `current_device_id` int(11) DEFAULT NULL,
  `current_port_number` int(11) DEFAULT NULL,
  `current_vlan_id` int(11) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `device_type` varchar(100) DEFAULT NULL,
  `domain_user` varchar(255) DEFAULT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `last_moved` datetime DEFAULT NULL,
  `move_count` int(11) DEFAULT 0,
  `previous_device_id` int(11) DEFAULT NULL,
  `previous_port_number` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `mac_device_import_history`
--

CREATE TABLE `mac_device_import_history` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `total_rows` int(11) DEFAULT NULL,
  `success_count` int(11) DEFAULT NULL,
  `error_count` int(11) DEFAULT NULL,
  `imported_by` varchar(100) DEFAULT NULL,
  `import_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `errors` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `mac_device_registry`
--

CREATE TABLE `mac_device_registry` (
  `id` int(11) NOT NULL,
  `mac_address` varchar(17) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `source` enum('domain','excel','manual','snmp_hub_auto') DEFAULT 'manual',
  `match_status` enum('updated','already_current','unmatched') DEFAULT NULL,
  `last_seen` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` varchar(100) DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `maintenance_windows`
--

CREATE TABLE `maintenance_windows` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notify_users` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migration_history`
--

CREATE TABLE `migration_history` (
  `id` int(11) NOT NULL,
  `migration_name` varchar(255) NOT NULL,
  `migration_type` enum('SQL','PYTHON','PHP') NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT 1,
  `error_message` text DEFAULT NULL,
  `execution_time_ms` int(11) DEFAULT NULL,
  `applied_by` varchar(100) DEFAULT 'system'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks applied database migrations';

--
--


-- --------------------------------------------------------

--
-- Stand-in structure for view `migration_stats`
-- (See below for the actual view)
--
CREATE TABLE `migration_stats` (
`migration_type` enum('SQL','PYTHON','PHP')
,`total_count` bigint(21)
,`success_count` decimal(22,0)
,`failed_count` decimal(22,0)
,`avg_execution_time_ms` decimal(14,4)
,`last_applied` timestamp
);

-- --------------------------------------------------------

--
-- Table structure for table `patch_panels`
--

CREATE TABLE `patch_panels` (
  `id` int(11) NOT NULL,
  `rack_id` int(11) NOT NULL,
  `panel_letter` varchar(1) NOT NULL,
  `total_ports` int(11) DEFAULT 24,
  `description` varchar(255) DEFAULT NULL,
  `position_in_rack` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `patch_ports`
--

CREATE TABLE `patch_ports` (
  `id` int(11) NOT NULL,
  `panel_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `status` enum('active','inactive','reserved') DEFAULT 'inactive',
  `connected_switch_id` int(11) DEFAULT NULL,
  `connected_port_no` int(11) DEFAULT NULL,
  `device` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `sync_version` int(11) DEFAULT 0,
  `connected_switch_port` int(11) DEFAULT NULL,
  `connection_type` enum('direct','jump_point','switch_to_panel','panel_to_switch') DEFAULT 'direct',
  `connection_details` text DEFAULT NULL,
  `connection_info_preserved` text DEFAULT NULL,
  `connected_to` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `ports`
--

CREATE TABLE `ports` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) DEFAULT NULL,
  `port_no` int(11) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `device` text DEFAULT NULL,
  `ip` text DEFAULT NULL,
  `mac` text DEFAULT NULL,
  `rack_port` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sync_version` int(11) DEFAULT 0,
  `patch_port_id` int(11) DEFAULT NULL,
  `is_hub` tinyint(1) DEFAULT 0,
  `hub_name` varchar(100) DEFAULT NULL,
  `multiple_connections` text DEFAULT NULL,
  `connection_info` text DEFAULT NULL,
  `device_count` int(11) DEFAULT 0,
  `connected_panel_id` int(11) DEFAULT NULL,
  `connected_panel_port` int(11) DEFAULT NULL,
  `panel_type` enum('patch','fiber') DEFAULT NULL,
  `connection_info_preserved` text DEFAULT NULL,
  `connected_to` varchar(255) DEFAULT NULL,
  `is_reserved` tinyint(1) DEFAULT 0 COMMENT 'Port rezerve mi?',
  `reserved_for` varchar(255) DEFAULT NULL COMMENT 'Kim için rezerve?',
  `oper_status` varchar(20) DEFAULT 'unknown' COMMENT 'Operational status from SNMP (up/down)',
  `last_status_update` datetime DEFAULT NULL COMMENT 'Last time operational status was updated'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `port_change_history`
--

CREATE TABLE `port_change_history` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `change_type` enum('mac_added','mac_removed','mac_moved','vlan_changed','description_changed','status_changed') NOT NULL,
  `change_timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `old_value` text DEFAULT NULL,
  `old_mac_address` varchar(17) DEFAULT NULL,
  `old_vlan_id` int(11) DEFAULT NULL,
  `old_description` varchar(255) DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `new_mac_address` varchar(17) DEFAULT NULL,
  `new_vlan_id` int(11) DEFAULT NULL,
  `new_description` varchar(255) DEFAULT NULL,
  `from_device_id` int(11) DEFAULT NULL,
  `from_port_number` int(11) DEFAULT NULL,
  `to_device_id` int(11) DEFAULT NULL,
  `to_port_number` int(11) DEFAULT NULL,
  `change_details` text DEFAULT NULL,
  `alarm_created` tinyint(1) DEFAULT 0,
  `alarm_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `port_snapshot`
--

CREATE TABLE `port_snapshot` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `snapshot_timestamp` datetime NOT NULL,
  `port_name` varchar(100) DEFAULT NULL,
  `port_alias` varchar(255) DEFAULT NULL,
  `port_description` text DEFAULT NULL,
  `admin_status` varchar(20) DEFAULT NULL,
  `oper_status` varchar(20) DEFAULT NULL,
  `vlan_id` int(11) DEFAULT NULL,
  `vlan_name` varchar(100) DEFAULT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `mac_addresses` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `port_status_data`
--

CREATE TABLE `port_status_data` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `poll_timestamp` datetime NOT NULL,
  `port_name` varchar(255) DEFAULT NULL,
  `port_alias` varchar(255) DEFAULT NULL,
  `port_description` text DEFAULT NULL,
  `admin_status` enum('UP','DOWN','TESTING') DEFAULT 'DOWN',
  `oper_status` enum('UP','DOWN','TESTING','UNKNOWN','DORMANT','NOTPRESENT','LOWERLAYERDOWN') DEFAULT 'DOWN',
  `last_change` datetime DEFAULT NULL,
  `port_type` varchar(100) DEFAULT NULL,
  `port_speed` bigint(20) DEFAULT NULL,
  `port_mtu` int(11) DEFAULT NULL,
  `vlan_id` int(11) DEFAULT NULL,
  `vlan_name` varchar(255) DEFAULT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `mac_addresses` text DEFAULT NULL,
  `packets_in` bigint(20) DEFAULT NULL,
  `packets_out` bigint(20) DEFAULT NULL,
  `first_seen` datetime DEFAULT NULL,
  `in_octets` bigint(20) DEFAULT NULL,
  `out_octets` bigint(20) DEFAULT NULL,
  `in_errors` bigint(20) DEFAULT NULL,
  `out_errors` bigint(20) DEFAULT NULL,
  `in_discards` bigint(20) DEFAULT NULL,
  `out_discards` bigint(20) DEFAULT NULL,
  `last_seen` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `port_traffic_history`
--

CREATE TABLE `port_traffic_history` (
  `id` bigint(20) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `sample_time` datetime NOT NULL,
  `in_octets` bigint(20) DEFAULT 0,
  `out_octets` bigint(20) DEFAULT 0,
  `in_bps` bigint(20) DEFAULT NULL,
  `out_bps` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `racks`
--

CREATE TABLE `racks` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `slots` int(11) DEFAULT 42,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `rack_devices`
--

CREATE TABLE `rack_devices` (
  `id` int(11) NOT NULL,
  `rack_id` int(11) NOT NULL,
  `device_type` enum('server','hub_sw') NOT NULL,
  `name` varchar(120) NOT NULL,
  `ports` smallint(6) NOT NULL DEFAULT 0,
  `unit_size` tinyint(4) NOT NULL DEFAULT 1,
  `position_in_rack` tinyint(4) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `fiber_ports` smallint(6) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Stand-in structure for view `recent_migrations`
-- (See below for the actual view)
--
CREATE TABLE `recent_migrations` (
`id` int(11)
,`migration_name` varchar(255)
,`migration_type` enum('SQL','PYTHON','PHP')
,`applied_at` timestamp
,`success` tinyint(1)
,`execution_time_ms` int(11)
,`status` varchar(7)
);

-- --------------------------------------------------------

--
-- Table structure for table `snmp_config`
--

CREATE TABLE `snmp_config` (
  `id` int(11) NOT NULL,
  `switch_id` int(11) DEFAULT NULL,
  `version` varchar(10) DEFAULT 'v2c',
  `community` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `auth_protocol` varchar(10) DEFAULT NULL,
  `auth_password` varchar(255) DEFAULT NULL,
  `priv_protocol` varchar(10) DEFAULT NULL,
  `priv_password` varchar(255) DEFAULT NULL,
  `timeout` int(11) DEFAULT 5,
  `retries` int(11) DEFAULT 3,
  `is_global` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `snmp_core_ports`
--

CREATE TABLE `snmp_core_ports` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `core_switch_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `snmp_devices`
--

CREATE TABLE `snmp_devices` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `status` enum('ONLINE','OFFLINE','UNREACHABLE','ERROR') DEFAULT 'OFFLINE',
  `enabled` tinyint(1) DEFAULT 1,
  `total_ports` int(11) DEFAULT 0,
  `last_poll_time` datetime DEFAULT NULL,
  `last_successful_poll` datetime DEFAULT NULL,
  `poll_interval` int(11) DEFAULT 300,
  `snmp_version` varchar(10) DEFAULT 'v2c',
  `snmp_community` varchar(100) DEFAULT 'public',
  `snmp_port` int(11) DEFAULT 161,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `snmp_v3_username` varchar(100) DEFAULT NULL,
  `snmp_v3_auth_protocol` varchar(20) DEFAULT NULL,
  `snmp_v3_auth_password` varchar(200) DEFAULT NULL,
  `snmp_v3_priv_protocol` varchar(20) DEFAULT NULL,
  `snmp_v3_priv_password` varchar(200) DEFAULT NULL,
  `system_description` text DEFAULT NULL,
  `system_uptime` int(11) DEFAULT NULL,
  `poll_failures` int(11) DEFAULT 0,
  `snmp_engine_id` varchar(100) DEFAULT NULL COMMENT 'SNMPv3 Engine ID (hex string)',
  `fan_status` varchar(20) DEFAULT NULL,
  `temperature_c` float DEFAULT NULL,
  `poe_nominal_w` int(11) DEFAULT NULL,
  `poe_consumed_w` int(11) DEFAULT NULL,
  `cpu_1min` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `snmp_uplink_ports`
--

CREATE TABLE `snmp_uplink_ports` (
  `id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `switches`
--

CREATE TABLE `switches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `ports` int(11) DEFAULT NULL,
  `status` enum('online','offline') DEFAULT 'online',
  `rack_id` int(11) DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `position_in_rack` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `is_virtual` tinyint(1) NOT NULL DEFAULT 0,
  `is_core` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `vlan_names`
--

CREATE TABLE `vlan_names` (
  `vlan_id` int(11) NOT NULL,
  `vlan_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(7) DEFAULT '#6c757d',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_inconsistent_connections`
-- (See below for the actual view)
--
CREATE TABLE `vw_inconsistent_connections` (
`issue_type` varchar(39)
,`port_id` int(11)
,`switch_id` int(11)
,`port_no` int(11)
,`connected_panel_id` int(11)
,`connected_panel_port` int(11)
,`panel_type` varchar(5)
,`panel_port_id` int(11)
,`description` varchar(156)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_ports_with_vlan_names`
-- (See below for the actual view)
--
CREATE TABLE `v_ports_with_vlan_names` (
`id` int(11)
,`device_id` int(11)
,`port_number` int(11)
,`port_name` varchar(255)
,`port_alias` varchar(255)
,`port_description` text
,`admin_status` enum('UP','DOWN','TESTING')
,`oper_status` enum('UP','DOWN','TESTING','UNKNOWN','DORMANT','NOTPRESENT','LOWERLAYERDOWN')
,`vlan_id` int(11)
,`vlan_name` varchar(50)
,`vlan_description` varchar(255)
,`vlan_color` varchar(7)
,`mac_address` varchar(17)
,`port_speed` bigint(20)
,`port_type` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_port_current_status`
-- (See below for the actual view)
--
CREATE TABLE `v_port_current_status` (
`device_id` int(11)
,`device_name` varchar(255)
,`ip_address` varchar(45)
,`port_number` int(11)
,`port_name` varchar(255)
,`port_alias` varchar(255)
,`port_description` text
,`admin_status` enum('UP','DOWN','TESTING')
,`oper_status` enum('UP','DOWN','TESTING','UNKNOWN','DORMANT','NOTPRESENT','LOWERLAYERDOWN')
,`vlan_id` int(11)
,`vlan_name` varchar(255)
,`mac_address` varchar(17)
,`mac_addresses` text
,`poll_timestamp` datetime
,`mac_device_name` varchar(255)
,`mac_ip_address` varchar(45)
,`active_alarm_count` bigint(21)
,`changes_last_24h` bigint(21)
);

-- --------------------------------------------------------

--
-- Structure for view `failed_migrations`
--
DROP TABLE IF EXISTS `failed_migrations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `failed_migrations`  AS SELECT `migration_history`.`id` AS `id`, `migration_history`.`migration_name` AS `migration_name`, `migration_history`.`migration_type` AS `migration_type`, `migration_history`.`applied_at` AS `applied_at`, `migration_history`.`error_message` AS `error_message`, `migration_history`.`execution_time_ms` AS `execution_time_ms` FROM `migration_history` WHERE `migration_history`.`success` = 0 ORDER BY `migration_history`.`applied_at` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `migration_stats`
--
DROP TABLE IF EXISTS `migration_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `migration_stats`  AS SELECT `migration_history`.`migration_type` AS `migration_type`, count(0) AS `total_count`, sum(case when `migration_history`.`success` = 1 then 1 else 0 end) AS `success_count`, sum(case when `migration_history`.`success` = 0 then 1 else 0 end) AS `failed_count`, avg(`migration_history`.`execution_time_ms`) AS `avg_execution_time_ms`, max(`migration_history`.`applied_at`) AS `last_applied` FROM `migration_history` GROUP BY `migration_history`.`migration_type` ;

-- --------------------------------------------------------

--
-- Structure for view `recent_migrations`
--
DROP TABLE IF EXISTS `recent_migrations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `recent_migrations`  AS SELECT `migration_history`.`id` AS `id`, `migration_history`.`migration_name` AS `migration_name`, `migration_history`.`migration_type` AS `migration_type`, `migration_history`.`applied_at` AS `applied_at`, `migration_history`.`success` AS `success`, `migration_history`.`execution_time_ms` AS `execution_time_ms`, CASE WHEN `migration_history`.`success` = 1 THEN 'SUCCESS' ELSE 'FAILED' END AS `status` FROM `migration_history` ORDER BY `migration_history`.`applied_at` DESC LIMIT 0, 50 ;

-- --------------------------------------------------------

--
-- Structure for view `vw_inconsistent_connections`
--
DROP TABLE IF EXISTS `vw_inconsistent_connections`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_inconsistent_connections`  AS SELECT 'port_has_panel_but_panel_missing' AS `issue_type`, `p`.`id` AS `port_id`, `p`.`switch_id` AS `switch_id`, `p`.`port_no` AS `port_no`, `p`.`connected_panel_id` AS `connected_panel_id`, `p`.`connected_panel_port` AS `connected_panel_port`, `p`.`panel_type` AS `panel_type`, NULL AS `panel_port_id`, concat('Switch ',`s`.`name`,' Port ',`p`.`port_no`,' → Panel ',`p`.`connected_panel_id`,':',`p`.`connected_panel_port`) AS `description` FROM (`ports` `p` join `switches` `s` on(`p`.`switch_id` = `s`.`id`)) WHERE `p`.`connected_panel_id` is not null AND `p`.`connected_panel_port` is not null AND !exists(select 1 from `patch_ports` `pp` where `pp`.`panel_id` = `p`.`connected_panel_id` AND `pp`.`port_number` = `p`.`connected_panel_port` AND `p`.`panel_type` = 'patch' union select 1 from `fiber_ports` `fp` where `fp`.`panel_id` = `p`.`connected_panel_id` AND `fp`.`port_number` = `p`.`connected_panel_port` AND `p`.`panel_type` = 'fiber' limit 1)union all select 'panel_has_switch_but_port_missing' AS `issue_type`,NULL AS `port_id`,`pp`.`connected_switch_id` AS `switch_id`,`pp`.`connected_switch_port` AS `port_no`,`pp`.`panel_id` AS `connected_panel_id`,`pp`.`port_number` AS `connected_panel_port`,'patch' AS `panel_type`,`pp`.`id` AS `panel_port_id`,concat('Panel ',`pp`.`panel_id`,':',`pp`.`port_number`,' → Switch ',`pp`.`connected_switch_id`,':',`pp`.`connected_switch_port`) AS `description` from `patch_ports` `pp` where `pp`.`connected_switch_id` is not null and `pp`.`connected_switch_port` is not null and !exists(select 1 from `ports` `p` where `p`.`switch_id` = `pp`.`connected_switch_id` and `p`.`port_no` = `pp`.`connected_switch_port` and `p`.`connected_panel_id` = `pp`.`panel_id` and `p`.`connected_panel_port` = `pp`.`port_number` limit 1) union all select 'fiber_panel_has_switch_but_port_missing' AS `issue_type`,NULL AS `port_id`,`fp`.`connected_switch_id` AS `switch_id`,`fp`.`connected_switch_port` AS `port_no`,`fp`.`panel_id` AS `connected_panel_id`,`fp`.`port_number` AS `connected_panel_port`,'fiber' AS `panel_type`,`fp`.`id` AS `panel_port_id`,concat('Fiber Panel ',`fp`.`panel_id`,':',`fp`.`port_number`,' → Switch ',`fp`.`connected_switch_id`,':',`fp`.`connected_switch_port`) AS `description` from `fiber_ports` `fp` where `fp`.`connected_switch_id` is not null and `fp`.`connected_switch_port` is not null and !exists(select 1 from `ports` `p` where `p`.`switch_id` = `fp`.`connected_switch_id` and `p`.`port_no` = `fp`.`connected_switch_port` and `p`.`connected_panel_id` = `fp`.`panel_id` and `p`.`connected_panel_port` = `fp`.`port_number` limit 1)  ;

-- --------------------------------------------------------

--
-- Structure for view `v_ports_with_vlan_names`
--
DROP TABLE IF EXISTS `v_ports_with_vlan_names`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_ports_with_vlan_names`  AS SELECT `ps`.`id` AS `id`, `ps`.`device_id` AS `device_id`, `ps`.`port_number` AS `port_number`, `ps`.`port_name` AS `port_name`, `ps`.`port_alias` AS `port_alias`, `ps`.`port_description` AS `port_description`, `ps`.`admin_status` AS `admin_status`, `ps`.`oper_status` AS `oper_status`, `ps`.`vlan_id` AS `vlan_id`, `vn`.`vlan_name` AS `vlan_name`, `vn`.`description` AS `vlan_description`, `vn`.`color` AS `vlan_color`, `ps`.`mac_address` AS `mac_address`, `ps`.`port_speed` AS `port_speed`, `ps`.`port_type` AS `port_type` FROM (`port_status_data` `ps` left join `vlan_names` `vn` on(`ps`.`vlan_id` = `vn`.`vlan_id`)) ORDER BY `ps`.`device_id` ASC, `ps`.`port_number` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_port_current_status`
--
DROP TABLE IF EXISTS `v_port_current_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_port_current_status`  AS SELECT `sd`.`id` AS `device_id`, `sd`.`name` AS `device_name`, `sd`.`ip_address` AS `ip_address`, `psd`.`port_number` AS `port_number`, `psd`.`port_name` AS `port_name`, `psd`.`port_alias` AS `port_alias`, `psd`.`port_description` AS `port_description`, `psd`.`admin_status` AS `admin_status`, `psd`.`oper_status` AS `oper_status`, `psd`.`vlan_id` AS `vlan_id`, `psd`.`vlan_name` AS `vlan_name`, `psd`.`mac_address` AS `mac_address`, `psd`.`mac_addresses` AS `mac_addresses`, `psd`.`poll_timestamp` AS `poll_timestamp`, `mat`.`device_name` AS `mac_device_name`, `mat`.`ip_address` AS `mac_ip_address`, (select count(0) from `alarms` `a` where `a`.`device_id` = `sd`.`id` and `a`.`port_number` = `psd`.`port_number` and `a`.`status` = 'active') AS `active_alarm_count`, (select count(0) from `port_change_history` `pch` where `pch`.`device_id` = `sd`.`id` and `pch`.`port_number` = `psd`.`port_number` and `pch`.`change_timestamp` > current_timestamp() - interval 24 hour) AS `changes_last_24h` FROM ((`snmp_devices` `sd` join `port_status_data` `psd` on(`sd`.`id` = `psd`.`device_id`)) left join `mac_address_tracking` `mat` on(`psd`.`mac_address` = `mat`.`mac_address`)) WHERE `psd`.`poll_timestamp` = (select max(`port_status_data`.`poll_timestamp`) from `port_status_data` where `port_status_data`.`device_id` = `sd`.`id` AND `port_status_data`.`port_number` = `psd`.`port_number`) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acknowledged_port_mac`
--
ALTER TABLE `acknowledged_port_mac`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_whitelist` (`device_name`,`port_number`,`mac_address`),
  ADD KEY `idx_device_port` (`device_name`,`port_number`),
  ADD KEY `idx_mac` (`mac_address`),
  ADD KEY `idx_acknowledged_at` (`acknowledged_at`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `alarms`
--
ALTER TABLE `alarms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alarms_device` (`device_id`),
  ADD KEY `idx_alarms_status` (`status`),
  ADD KEY `idx_alarms_severity` (`severity`),
  ADD KEY `idx_alarms_type` (`alarm_type`),
  ADD KEY `idx_alarms_occurrence` (`last_occurrence`),
  ADD KEY `idx_mac_address` (`mac_address`),
  ADD KEY `idx_alarm_fingerprint` (`alarm_fingerprint`),
  ADD KEY `idx_device_port_type_status` (`device_id`,`port_number`,`alarm_type`,`status`),
  ADD KEY `idx_is_silenced` (`is_silenced`);

--
-- Indexes for table `alarm_history`
--
ALTER TABLE `alarm_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alarm_history_alarm` (`alarm_id`),
  ADD KEY `idx_alarm_history_timestamp` (`changed_at`);

--
-- Indexes for table `alarm_severity_config`
--
ALTER TABLE `alarm_severity_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `alarm_type` (`alarm_type`),
  ADD KEY `idx_alarm_type` (`alarm_type`),
  ADD KEY `idx_severity` (`severity`);

--
-- Indexes for table `connection_history`
--
ALTER TABLE `connection_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_source` (`source_type`,`source_id`,`source_port`),
  ADD KEY `idx_target` (`target_type`,`target_id`,`target_port`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `device_polling_data`
--
ALTER TABLE `device_polling_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_polling_device` (`device_id`),
  ADD KEY `idx_device_polling_timestamp` (`poll_timestamp`);

--
-- Indexes for table `fiber_panels`
--
ALTER TABLE `fiber_panels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_panel` (`rack_id`,`panel_letter`),
  ADD KEY `idx_fiber_panels_rack_position` (`rack_id`,`position_in_rack`),
  ADD KEY `idx_fiber_panels_rack_letter` (`rack_id`,`panel_letter`);

--
-- Indexes for table `fiber_ports`
--
ALTER TABLE `fiber_ports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_fiber_port` (`panel_id`,`port_number`),
  ADD KEY `idx_fiber_ports_connections` (`connected_switch_id`,`connected_fiber_panel_id`),
  ADD KEY `idx_fiber_ports_panel` (`panel_id`,`port_number`),
  ADD KEY `idx_fiber_ports_switch` (`connected_switch_id`,`connected_switch_port`),
  ADD KEY `idx_fiber_connected_panel` (`connected_fiber_panel_id`,`connected_fiber_panel_port`),
  ADD KEY `idx_fiber_connected_switch` (`connected_switch_id`,`connected_switch_port`),
  ADD KEY `idx_fiber_panel_port` (`panel_id`,`port_number`);

--
-- Indexes for table `hub_sw_port_connections`
--
ALTER TABLE `hub_sw_port_connections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_rdport` (`rack_device_id`,`port_number`),
  ADD KEY `idx_rd` (`rack_device_id`);

--
-- Indexes for table `mac_address_tracking`
--
ALTER TABLE `mac_address_tracking`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mac_address` (`mac_address`),
  ADD KEY `idx_current_location` (`current_device_id`,`current_port_number`),
  ADD KEY `idx_last_seen` (`last_seen`);

--
-- Indexes for table `mac_device_import_history`
--
ALTER TABLE `mac_device_import_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`import_date`);

--
-- Indexes for table `mac_device_registry`
--
ALTER TABLE `mac_device_registry`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `mac_address` (`mac_address`),
  ADD KEY `idx_mac` (`mac_address`),
  ADD KEY `idx_ip` (`ip_address`),
  ADD KEY `idx_device_name` (`device_name`),
  ADD KEY `idx_source` (`source`),
  ADD KEY `idx_updated` (`updated_at`);

--
-- Indexes for table `maintenance_windows`
--
ALTER TABLE `maintenance_windows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mw_times` (`start_time`,`end_time`),
  ADD KEY `idx_mw_active` (`is_active`);

--
-- Indexes for table `migration_history`
--
ALTER TABLE `migration_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `migration_name` (`migration_name`),
  ADD KEY `idx_applied_at` (`applied_at`),
  ADD KEY `idx_migration_type` (`migration_type`),
  ADD KEY `idx_success` (`success`);

--
-- Indexes for table `patch_panels`
--
ALTER TABLE `patch_panels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_panel` (`rack_id`,`panel_letter`),
  ADD UNIQUE KEY `unique_position` (`rack_id`,`position_in_rack`),
  ADD KEY `idx_patch_panels_rack` (`rack_id`),
  ADD KEY `idx_patch_panels_position` (`position_in_rack`),
  ADD KEY `idx_patch_panels_rack_position` (`rack_id`,`position_in_rack`),
  ADD KEY `idx_panels_rack_letter` (`rack_id`,`panel_letter`);

--
-- Indexes for table `patch_ports`
--
ALTER TABLE `patch_ports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_patch_port` (`panel_id`,`port_number`),
  ADD KEY `idx_patch_ports_panel` (`panel_id`),
  ADD KEY `idx_patch_ports_status` (`status`),
  ADD KEY `idx_patch_ports_connection` (`connected_switch_id`,`connected_port_no`),
  ADD KEY `idx_patch_ports_switch_connection` (`connected_switch_id`,`connected_switch_port`),
  ADD KEY `idx_patch_ports_switch` (`connected_switch_id`,`connected_switch_port`);

--
-- Indexes for table `ports`
--
ALTER TABLE `ports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_port` (`switch_id`,`port_no`),
  ADD KEY `idx_ports_switch` (`switch_id`),
  ADD KEY `idx_ports_patch` (`patch_port_id`),
  ADD KEY `idx_ports_switch_port` (`switch_id`,`port_no`),
  ADD KEY `idx_ports_is_hub` (`is_hub`),
  ADD KEY `idx_ports_panel_connection` (`connected_panel_id`,`connected_panel_port`),
  ADD KEY `idx_ports_connected_to` (`connected_to`(100)),
  ADD KEY `idx_ports_panel` (`connected_panel_id`,`connected_panel_port`),
  ADD KEY `idx_ports_panel_type` (`panel_type`);
ALTER TABLE `ports` ADD FULLTEXT KEY `idx_multiple_connections` (`multiple_connections`);
ALTER TABLE `ports` ADD FULLTEXT KEY `idx_ports_ip` (`ip`);
ALTER TABLE `ports` ADD FULLTEXT KEY `idx_ports_mac` (`mac`);

--
-- Indexes for table `port_change_history`
--
ALTER TABLE `port_change_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_device_port` (`device_id`,`port_number`),
  ADD KEY `idx_change_type` (`change_type`),
  ADD KEY `idx_timestamp` (`change_timestamp`),
  ADD KEY `idx_mac_address` (`old_mac_address`,`new_mac_address`),
  ADD KEY `idx_alarm` (`alarm_id`);

--
-- Indexes for table `port_snapshot`
--
ALTER TABLE `port_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ps_device_port` (`device_id`,`port_number`),
  ADD KEY `idx_timestamp` (`snapshot_timestamp`);

--
-- Indexes for table `port_status_data`
--
ALTER TABLE `port_status_data`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_device_port` (`device_id`,`port_number`),
  ADD KEY `idx_port_status_device` (`device_id`),
  ADD KEY `idx_port_status_port` (`device_id`,`port_number`),
  ADD KEY `idx_port_status_timestamp` (`poll_timestamp`),
  ADD KEY `idx_port_status_oper` (`oper_status`);

--
-- Indexes for table `port_traffic_history`
--
ALTER TABLE `port_traffic_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pth_device_port` (`device_id`,`port_number`),
  ADD KEY `idx_pth_time` (`sample_time`),
  ADD KEY `idx_pth_device_port_time` (`device_id`,`port_number`,`sample_time`);

--
-- Indexes for table `racks`
--
ALTER TABLE `racks`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rack_devices`
--
ALTER TABLE `rack_devices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rack` (`rack_id`);

--
-- Indexes for table `snmp_config`
--
ALTER TABLE `snmp_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_switch_snmp` (`switch_id`);

--
-- Indexes for table `snmp_core_ports`
--
ALTER TABLE `snmp_core_ports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_core_port` (`device_id`,`port_number`);

--
-- Indexes for table `snmp_devices`
--
ALTER TABLE `snmp_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ip_address` (`ip_address`),
  ADD KEY `idx_snmp_devices_ip` (`ip_address`),
  ADD KEY `idx_snmp_devices_status` (`status`),
  ADD KEY `idx_snmp_devices_enabled` (`enabled`);

--
-- Indexes for table `snmp_uplink_ports`
--
ALTER TABLE `snmp_uplink_ports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uplink_port` (`device_id`,`port_number`);

--
-- Indexes for table `switches`
--
ALTER TABLE `switches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD UNIQUE KEY `unique_rack_position` (`rack_id`,`position_in_rack`),
  ADD UNIQUE KEY `unique_switch_name` (`name`),
  ADD KEY `idx_switches_rack` (`rack_id`),
  ADD KEY `idx_switches_position` (`position_in_rack`),
  ADD KEY `idx_switches_ip` (`ip`),
  ADD KEY `idx_switches_rack_position` (`rack_id`,`position_in_rack`),
  ADD KEY `idx_switches_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `vlan_names`
--
ALTER TABLE `vlan_names`
  ADD PRIMARY KEY (`vlan_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acknowledged_port_mac`
--
ALTER TABLE `acknowledged_port_mac`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=546;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1985;

--
-- AUTO_INCREMENT for table `alarms`
--
ALTER TABLE `alarms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3454;

--
-- AUTO_INCREMENT for table `alarm_history`
--
ALTER TABLE `alarm_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5610;

--
-- AUTO_INCREMENT for table `alarm_severity_config`
--
ALTER TABLE `alarm_severity_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `connection_history`
--
ALTER TABLE `connection_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `device_polling_data`
--
ALTER TABLE `device_polling_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1406925;

--
-- AUTO_INCREMENT for table `fiber_panels`
--
ALTER TABLE `fiber_panels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `fiber_ports`
--
ALTER TABLE `fiber_ports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=414;

--
-- AUTO_INCREMENT for table `hub_sw_port_connections`
--
ALTER TABLE `hub_sw_port_connections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `mac_address_tracking`
--
ALTER TABLE `mac_address_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3015;

--
-- AUTO_INCREMENT for table `mac_device_import_history`
--
ALTER TABLE `mac_device_import_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `mac_device_registry`
--
ALTER TABLE `mac_device_registry`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8512502;

--
-- AUTO_INCREMENT for table `maintenance_windows`
--
ALTER TABLE `maintenance_windows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migration_history`
--
ALTER TABLE `migration_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `patch_panels`
--
ALTER TABLE `patch_panels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=71;

--
-- AUTO_INCREMENT for table `patch_ports`
--
ALTER TABLE `patch_ports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2042;

--
-- AUTO_INCREMENT for table `ports`
--
ALTER TABLE `ports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61705;

--
-- AUTO_INCREMENT for table `port_change_history`
--
ALTER TABLE `port_change_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=256140;

--
-- AUTO_INCREMENT for table `port_snapshot`
--
ALTER TABLE `port_snapshot`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66496140;

--
-- AUTO_INCREMENT for table `port_status_data`
--
ALTER TABLE `port_status_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7215814;

--
-- AUTO_INCREMENT for table `port_traffic_history`
--
ALTER TABLE `port_traffic_history`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `racks`
--
ALTER TABLE `racks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `rack_devices`
--
ALTER TABLE `rack_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `snmp_config`
--
ALTER TABLE `snmp_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `snmp_core_ports`
--
ALTER TABLE `snmp_core_ports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1132423;

--
-- AUTO_INCREMENT for table `snmp_devices`
--
ALTER TABLE `snmp_devices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=69;

--
-- AUTO_INCREMENT for table `snmp_uplink_ports`
--
ALTER TABLE `snmp_uplink_ports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `switches`
--
ALTER TABLE `switches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=757;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `fk_activity_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `alarms`
--
ALTER TABLE `alarms`
  ADD CONSTRAINT `alarms_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `alarm_history`
--
ALTER TABLE `alarm_history`
  ADD CONSTRAINT `alarm_history_ibfk_1` FOREIGN KEY (`alarm_id`) REFERENCES `alarms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `device_polling_data`
--
ALTER TABLE `device_polling_data`
  ADD CONSTRAINT `device_polling_data_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fiber_panels`
--
ALTER TABLE `fiber_panels`
  ADD CONSTRAINT `fiber_panels_ibfk_1` FOREIGN KEY (`rack_id`) REFERENCES `racks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fiber_ports`
--
ALTER TABLE `fiber_ports`
  ADD CONSTRAINT `fiber_ports_ibfk_1` FOREIGN KEY (`panel_id`) REFERENCES `fiber_panels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fiber_ports_ibfk_2` FOREIGN KEY (`connected_switch_id`) REFERENCES `switches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fiber_ports_ibfk_3` FOREIGN KEY (`connected_fiber_panel_id`) REFERENCES `fiber_panels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fiber_ports_panel` FOREIGN KEY (`panel_id`) REFERENCES `fiber_panels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fiber_ports_switch` FOREIGN KEY (`connected_switch_id`) REFERENCES `switches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `mac_address_tracking`
--
ALTER TABLE `mac_address_tracking`
  ADD CONSTRAINT `fk_mat_device` FOREIGN KEY (`current_device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `patch_panels`
--
ALTER TABLE `patch_panels`
  ADD CONSTRAINT `patch_panels_ibfk_1` FOREIGN KEY (`rack_id`) REFERENCES `racks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patch_ports`
--
ALTER TABLE `patch_ports`
  ADD CONSTRAINT `fk_patch_ports_panel` FOREIGN KEY (`panel_id`) REFERENCES `patch_panels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_patch_ports_switch` FOREIGN KEY (`connected_switch_id`) REFERENCES `switches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `patch_ports_ibfk_1` FOREIGN KEY (`panel_id`) REFERENCES `patch_panels` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patch_ports_ibfk_2` FOREIGN KEY (`connected_switch_id`) REFERENCES `switches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ports`
--
ALTER TABLE `ports`
  ADD CONSTRAINT `fk_patch_port` FOREIGN KEY (`patch_port_id`) REFERENCES `patch_ports` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ports_connected_panel` FOREIGN KEY (`connected_panel_id`) REFERENCES `patch_panels` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ports_switch` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ports_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ports_ibfk_2` FOREIGN KEY (`connected_panel_id`) REFERENCES `patch_panels` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `port_change_history`
--
ALTER TABLE `port_change_history`
  ADD CONSTRAINT `fk_pch_device` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `port_snapshot`
--
ALTER TABLE `port_snapshot`
  ADD CONSTRAINT `fk_ps_device` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `port_status_data`
--
ALTER TABLE `port_status_data`
  ADD CONSTRAINT `port_status_data_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `port_traffic_history`
--
ALTER TABLE `port_traffic_history`
  ADD CONSTRAINT `port_traffic_history_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `snmp_config`
--
ALTER TABLE `snmp_config`
  ADD CONSTRAINT `fk_snmp_switch` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `snmp_core_ports`
--
ALTER TABLE `snmp_core_ports`
  ADD CONSTRAINT `snmp_core_ports_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `snmp_uplink_ports`
--
ALTER TABLE `snmp_uplink_ports`
  ADD CONSTRAINT `snmp_uplink_ports_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `snmp_devices` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `switches`
--
ALTER TABLE `switches`
  ADD CONSTRAINT `switches_ibfk_1` FOREIGN KEY (`rack_id`) REFERENCES `racks` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `evt_purge_device_polling_data` ON SCHEDULE EVERY 1 DAY STARTS '2026-03-20 00:00:00' ON COMPLETION PRESERVE ENABLE COMMENT 'Delete device_polling_data rows older than 7 days' DO DELETE FROM device_polling_data
    WHERE poll_timestamp < DATE_SUB(NOW(), INTERVAL 7 DAY)$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- =============================================================
--  Seed Data  (required for application startup)
-- =============================================================

--
-- Default alarm severity configuration
--
INSERT INTO `alarm_severity_config` (`id`, `alarm_type`, `severity`, `telegram_enabled`, `email_enabled`, `description`, `created_at`, `updated_at`) VALUES
(1,  'device_unreachable',  'CRITICAL', 0, 1, 'Cihaz erişilemez durumda',          NOW(), NOW()),
(2,  'multiple_ports_down', 'CRITICAL', 0, 1, 'Birden fazla port çalışmıyor',       NOW(), NOW()),
(3,  'mac_moved',           'CRITICAL', 0, 1, 'MAC adresi taşındı',                 NOW(), NOW()),
(4,  'port_down',           'LOW',      0, 0, 'Port düştü',                         NOW(), NOW()),
(5,  'vlan_changed',        'HIGH',     0, 1, 'VLAN değişti',                       NOW(), NOW()),
(6,  'port_up',             'LOW',      0, 0, 'Port aktif oldu',                    NOW(), NOW()),
(7,  'description_changed', 'MEDIUM',   0, 1, 'Port açıklaması değişti',            NOW(), NOW()),
(8,  'mac_added',           'CRITICAL', 0, 1, 'Yeni MAC adresi tespit edildi',      NOW(), NOW()),
(9,  'snmp_error',          'HIGH',     0, 1, 'SNMP hatası',                        NOW(), NOW()),
(80, 'core_link_down',      'CRITICAL', 0, 1, 'Core uplink bağlantısı kesildi',     NOW(), NOW());

--
-- Default admin user  (password: admin123  -- CHANGE THIS AFTER FIRST LOGIN)
-- Hash: bcrypt of "admin123"
--
INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
('admin', '$2y$10$4yxmJrFGCmT.2eUmEVm7vOdrNaJZI0T4przYixLVIe5SW.xqdw7VS', 'System Administrator', 'admin@example.com', 'admin', 1, NOW(), NOW());
