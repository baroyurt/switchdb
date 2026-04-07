<?php
/**
 * maintenance_windows_api.php — Bakım penceresi CRUD API
 */
include __DIR__ . '/../db.php';
include __DIR__ . '/../auth.php';
header('Content-Type: application/json; charset=utf-8');

$auth = new Auth($conn);
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Oturum açılmamış']);
    exit;
}
$currentUser = $auth->getUser();

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            listWindows();
            break;
        case 'save':
            requireAdmin();
            saveWindow();
            break;
        case 'delete':
            requireAdmin();
            deleteWindow();
            break;
        case 'check':
            checkActive();
            break;
        default:
            throw new Exception('Geçersiz aksiyon');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function requireAdmin() {
    global $currentUser;
    if (!isset($currentUser) || $currentUser['role'] !== 'admin') {
        throw new Exception('Yetki gerekli');
    }
}

function listWindows() {
    global $conn;
    $result = $conn->query("
        SELECT mw.*, sd.name as device_display_name
        FROM maintenance_windows mw
        LEFT JOIN snmp_devices sd ON sd.id = mw.device_id
        ORDER BY mw.start_time DESC
        LIMIT 200
    ");
    if (!$result) {
        throw new Exception('Veritabanı sorgusu başarısız: ' . $conn->error);
    }
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    echo json_encode(['success' => true, 'windows' => $rows]);
}

function saveWindow() {
    global $conn, $currentUser;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $id         = isset($input['id']) ? (int)$input['id'] : 0;
    $title      = trim($input['title'] ?? '');
    $deviceId   = isset($input['device_id']) && $input['device_id'] !== '' ? (int)$input['device_id'] : null;
    $deviceName = trim($input['device_name'] ?? '');
    $startTime  = $input['start_time'] ?? '';
    $endTime    = $input['end_time'] ?? '';
    $recurring  = isset($input['recurring']) ? (int)$input['recurring'] : 0;
    $recurDays  = trim($input['recur_days'] ?? '');
    $recurStart = trim($input['recur_start'] ?? '') ?: null;
    $recurEnd   = trim($input['recur_end'] ?? '') ?: null;
    $suppress   = isset($input['suppress_alarms']) ? (int)$input['suppress_alarms'] : 1;
    $createdBy  = $currentUser['username'] ?? 'admin';

    if (!$title) throw new Exception('Başlık zorunlu');
    if (!$startTime || !$endTime) throw new Exception('Başlangıç ve bitiş zamanı zorunlu');

    if ($id) {
        $stmt = $conn->prepare("UPDATE maintenance_windows SET title=?, device_id=?, device_name=?, start_time=?, end_time=?, recurring=?, recur_days=?, recur_start=?, recur_end=?, suppress_alarms=? WHERE id=?");
        $stmt->bind_param('sisssisssii', $title, $deviceId, $deviceName, $startTime, $endTime, $recurring, $recurDays, $recurStart, $recurEnd, $suppress, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO maintenance_windows (title, device_id, device_name, start_time, end_time, recurring, recur_days, recur_start, recur_end, suppress_alarms, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sisssisssis', $title, $deviceId, $deviceName, $startTime, $endTime, $recurring, $recurDays, $recurStart, $recurEnd, $suppress, $createdBy);
    }
    $stmt->execute();
    echo json_encode(['success' => true, 'id' => $id ?: $conn->insert_id]);
}

function deleteWindow() {
    global $conn;
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if (!$id) throw new Exception('id gerekli');
    $stmt = $conn->prepare("DELETE FROM maintenance_windows WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
}

function checkActive() {
    global $conn;
    $deviceId   = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;
    $deviceName = trim($_GET['device_name'] ?? '');
    $now = date('Y-m-d H:i:s');
    $dayNum = (int)date('N') - 1; // Converts PHP's date('N') 1-7 (Mon-Sun) to 0-6 (Mon-Sun)

    // Tek seferlik pencereler
    $query = "SELECT id, title FROM maintenance_windows 
              WHERE suppress_alarms = 1 
              AND (? BETWEEN start_time AND end_time)
              AND (device_id IS NULL OR device_id = ? OR device_name IS NULL OR device_name = '' OR device_name = ?)
              LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sis', $now, $deviceId, $deviceName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        echo json_encode(['success' => true, 'in_maintenance' => true, 'window' => $row]);
        return;
    }

    // Tekrarlayan pencereler
    $stmt2 = $conn->prepare("SELECT id, title, recur_days, recur_start, recur_end FROM maintenance_windows WHERE recurring = 1 AND suppress_alarms = 1 AND (device_id IS NULL OR device_id = ? OR device_name IS NULL OR device_name = '' OR device_name = ?)");
    $stmt2->bind_param('is', $deviceId, $deviceName);
    $stmt2->execute();
    $res = $stmt2->get_result();
    $currentTime = date('H:i:s');
    while ($mw = $res->fetch_assoc()) {
        $days = array_map('trim', explode(',', $mw['recur_days'] ?? ''));
        if (!in_array((string)$dayNum, $days)) continue;
        if ($mw['recur_start'] && $mw['recur_end']) {
            if ($currentTime >= $mw['recur_start'] && $currentTime <= $mw['recur_end']) {
                echo json_encode(['success' => true, 'in_maintenance' => true, 'window' => $mw]);
                return;
            }
        }
    }
    echo json_encode(['success' => true, 'in_maintenance' => false]);
}
