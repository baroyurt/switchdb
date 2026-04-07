<?php
/**
 * Traffic / Bandwidth API
 * Returns per-switch CPU, memory, and port in/out octet time-series
 * for the Admin Panel bandwidth graphs.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($conn);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
// Release session lock for concurrent requests
session_write_close();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'switch_list':
        getSwitchList($conn);
        break;
    case 'device_health':
        getDeviceHealth($conn);
        break;
    case 'port_traffic':
        getPortTraffic($conn);
        break;
    case 'bandwidth_summary':
        getBandwidthSummary($conn);
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
}

/**
 * List SNMP-monitored switches with latest health snapshot
 */
function getSwitchList($conn) {
    $sql = "SELECT
                sd.id,
                sd.name,
                sd.ip_address,
                sd.vendor,
                sd.model,
                sd.status,
                sd.last_poll_time,
                sd.total_ports,
                dpd.cpu_usage,
                dpd.memory_usage,
                dpd.temperature,
                dpd.active_ports,
                s.id AS sw_id
            FROM snmp_devices sd
            LEFT JOIN device_polling_data dpd ON dpd.id = (
                SELECT MAX(id) FROM device_polling_data WHERE device_id = sd.id
            )
            LEFT JOIN switches s ON (sd.name = s.name OR sd.ip_address = s.ip)
            ORDER BY sd.name";
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $list[] = $row;
        }
    }
    echo json_encode(['success' => true, 'devices' => $list]);
}

/**
 * CPU + memory history for a single device (last N hours)
 */
function getDeviceHealth($conn) {
    $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
    $hours    = isset($_GET['hours'])     ? max(1, min(168, (int)$_GET['hours'])) : 24; // max 7 days (168 hours)

    if ($deviceId <= 0) {
        echo json_encode(['error' => 'Invalid device_id']);
        return;
    }

    $since = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
    $sql = "SELECT poll_timestamp, cpu_usage, memory_usage, temperature, active_ports
            FROM device_polling_data
            WHERE device_id = ? AND poll_timestamp >= ?
            ORDER BY poll_timestamp ASC
            LIMIT 2000";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'DB error: ' . $conn->error]);
        return;
    }
    $stmt->bind_param('is', $deviceId, $since);
    $stmt->execute();
    $res  = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows, 'device_id' => $deviceId]);
}

/**
 * Port-level in/out octets for top N active ports of a device
 * Returns the latest snapshot per port plus the delta vs previous poll.
 */
function getPortTraffic($conn) {
    $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : 0;
    $limit    = isset($_GET['limit'])     ? max(1, min(48, (int)$_GET['limit'])) : 24;

    if ($deviceId <= 0) {
        echo json_encode(['error' => 'Invalid device_id']);
        return;
    }

    // Latest snapshot per port
    $sql = "SELECT
                psd.port_number,
                psd.port_name,
                psd.port_alias,
                psd.oper_status,
                psd.port_speed,
                psd.in_octets,
                psd.out_octets,
                psd.poll_timestamp
            FROM port_status_data psd
            INNER JOIN (
                SELECT port_number, MAX(id) AS max_id
                FROM port_status_data
                WHERE device_id = ?
                GROUP BY port_number
            ) latest ON psd.port_number = latest.port_number AND psd.id = latest.max_id
            WHERE psd.device_id = ? AND psd.oper_status = 'up'
            ORDER BY (COALESCE(psd.in_octets,0) + COALESCE(psd.out_octets,0)) DESC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['error' => 'DB error: ' . $conn->error]);
        return;
    }
    $stmt->bind_param('iii', $deviceId, $deviceId, $limit);
    $stmt->execute();
    $res   = $stmt->get_result();
    $ports = [];
    while ($r = $res->fetch_assoc()) {
        $ports[] = $r;
    }
    echo json_encode(['success' => true, 'ports' => $ports, 'device_id' => $deviceId]);
}

/**
 * Per-device bandwidth summary: total in/out octets across all active ports
 */
function getBandwidthSummary($conn) {
    $sql = "SELECT
                sd.id   AS device_id,
                sd.name AS device_name,
                sd.ip_address,
                sd.status,
                COALESCE(ps.total_in,0)  AS total_in_octets,
                COALESCE(ps.total_out,0) AS total_out_octets,
                COALESCE(ps.active_ports,0) AS active_ports,
                dpd.cpu_usage,
                dpd.memory_usage
            FROM snmp_devices sd
            LEFT JOIN (
                SELECT
                    psd.device_id,
                    SUM(COALESCE(psd.in_octets,0))  AS total_in,
                    SUM(COALESCE(psd.out_octets,0)) AS total_out,
                    COUNT(CASE WHEN psd.oper_status='up' THEN 1 END) AS active_ports
                FROM port_status_data psd
                INNER JOIN (
                    SELECT device_id, port_number, MAX(id) AS max_id
                    FROM port_status_data
                    GROUP BY device_id, port_number
                ) latest ON psd.device_id=latest.device_id
                        AND psd.port_number=latest.port_number
                        AND psd.id=latest.max_id
                GROUP BY psd.device_id
            ) ps ON ps.device_id = sd.id
            LEFT JOIN device_polling_data dpd ON dpd.id = (
                SELECT MAX(id) FROM device_polling_data WHERE device_id = sd.id
            )
            ORDER BY sd.name";

    $res  = $conn->query($sql);
    $data = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $data[] = $r;
        }
    }
    echo json_encode(['success' => true, 'summary' => $data]);
}
