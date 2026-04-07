<?php
include __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS hub_sw_port_connections (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    rack_device_id INT NOT NULL,
    port_number    SMALLINT NOT NULL,
    conn_type      VARCHAR(30) NOT NULL DEFAULT 'device',
    device_name    VARCHAR(255) DEFAULT NULL,
    switch_id      INT DEFAULT NULL,
    switch_port    INT DEFAULT NULL,
    notes          TEXT DEFAULT NULL,
    updated_at     DATETIME DEFAULT NOW() ON UPDATE NOW(),
    UNIQUE KEY uk_rdport (rack_device_id, port_number),
    INDEX idx_rd (rack_device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    die(json_encode(["success" => false, "error" => "Geçersiz veri"]));
}

try {
    $rdId    = intval($data['rdId'] ?? 0);
    $portNum = intval($data['portNum'] ?? 0);

    if ($rdId <= 0 || $portNum <= 0) {
        throw new Exception("Geçersiz rack_device_id veya port_number");
    }

    // Delete action
    if (($data['action'] ?? '') === 'delete') {
        $stmt = $conn->prepare("DELETE FROM hub_sw_port_connections WHERE rack_device_id = ? AND port_number = ?");
        $stmt->bind_param("ii", $rdId, $portNum);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["success" => true, "message" => "Bağlantı silindi"]);
        exit;
    }

    $connType   = $data['connType']    ?? 'device';
    $deviceName = trim($data['deviceName'] ?? '');
    $notes      = trim($data['notes']      ?? '');
    $swId       = isset($data['swId'])   ? intval($data['swId'])   : null;
    $swPort     = isset($data['swPort']) ? intval($data['swPort']) : null;

    if ($connType === 'switch' && (!$swId || !$swPort)) {
        throw new Exception("Switch ve port seçiniz");
    }
    if ($connType === 'device' && $deviceName === '') {
        throw new Exception("Cihaz adı boş olamaz");
    }

    $stmt = $conn->prepare("
        INSERT INTO hub_sw_port_connections (rack_device_id, port_number, conn_type, device_name, switch_id, switch_port, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            conn_type   = VALUES(conn_type),
            device_name = VALUES(device_name),
            switch_id   = VALUES(switch_id),
            switch_port = VALUES(switch_port),
            notes       = VALUES(notes),
            updated_at  = NOW()
    ");
    $stmt->bind_param("iississ", $rdId, $portNum, $connType, $deviceName, $swId, $swPort, $notes);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["success" => true, "message" => "Bağlantı kaydedildi"]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}

$conn->close();
?>
