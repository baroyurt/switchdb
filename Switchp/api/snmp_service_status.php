<?php
/**
 * Lightweight SNMP service status / start / stop endpoint.
 *
 * Called by admin.php Dashboard via fetch().  Kept intentionally tiny so that
 * the session lock is released before any exec() call and the response is fast.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($conn);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Release the session lock immediately — no further session writes needed.
session_write_close();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'service_status':
        $running = false;
        if (PHP_OS_FAMILY === 'Windows') {
            // ── Windows: check SC service first, then DB heartbeat ────────
            exec('sc query SNMPWorker 2>&1', $out);
            $running = (strpos(implode(' ', $out), 'RUNNING') !== false);
        }

        // ── DB-based heartbeat (works on all platforms) ───────────────────
        // If any enabled SNMP device was polled in the last 5 minutes the
        // worker process is definitely active.  Use this as the primary check
        // on Linux and as a secondary confirmation on Windows when SC reports
        // the service is not found.
        if (!$running) {
            try {
                $hbResult = $conn->query("
                    SELECT COUNT(*) AS cnt
                    FROM snmp_devices
                    WHERE enabled = 1
                      AND last_poll_time > NOW() - INTERVAL 5 MINUTE
                ");
                if ($hbResult) {
                    $hbRow = $hbResult->fetch_assoc();
                    $running = ($hbRow['cnt'] ?? 0) > 0;
                    $hbResult->free();
                }
            } catch (Exception $e) {
                // DB not available — fall through to exec() check below
            }
        }

        // ── Process-level fallback (Linux only) ──────────────────────────
        // Only attempted when the DB check found no recent activity, which
        // means either: (a) no SNMP devices are configured, or (b) the last
        // poll was > 5 minutes ago, or (c) the DB query failed.
        // Use a python-specific pattern to avoid matching intermediate shell
        // or timeout processes whose argv also contains "worker.py".
        if (!$running && PHP_OS_FAMILY !== 'Windows') {
            exec('pgrep -f "python.*worker\.py" 2>/dev/null', $pids);
            $running = count(array_filter($pids, 'strlen')) > 0;
            if (!$running) {
                exec('timeout 3 systemctl is-active snmp-worker 2>/dev/null', $svcOut);
                $running = (trim(implode('', $svcOut)) === 'active');
            }
        }

        echo json_encode([
            'success' => true,
            'running' => $running,
            'status'  => $running ? 'Çalışıyor' : 'Durduruldu',
        ]);
        break;

    case 'start_service':
        $ok  = false;
        $msg = '';
        if (PHP_OS_FAMILY === 'Windows') {
            exec('sc start SNMPWorker 2>&1', $out, $rc);
            $outStr = implode(' ', $out);
            $ok  = ($rc === 0
                || strpos($outStr, 'START_PENDING') !== false
                || strpos($outStr, 'RUNNING')       !== false);
            $msg = $ok
                ? 'Servis başlatıldı.'
                : 'Servis başlatılamadı. Lütfen Yönetici yetkisiyle XAMPP çalıştırın.';
        } else {
            exec('systemctl start snmp-worker 2>&1', $out, $rc);
            if ($rc !== 0) {
                $workerPath = realpath(__DIR__ . '/../snmp_worker/worker.py');
                if ($workerPath && strpos($workerPath, __DIR__) === 0) {
                    exec('nohup python3 ' . escapeshellarg($workerPath)
                        . ' > /dev/null 2>&1 &', $out2, $rc);
                }
            }
            $ok  = ($rc === 0);
            $msg = $ok ? 'Servis başlatıldı.' : 'Servis başlatılamadı.';
        }
        echo json_encode(['success' => $ok, 'message' => $msg]);
        break;

    case 'stop_service':
        $ok  = false;
        $msg = '';
        if (PHP_OS_FAMILY === 'Windows') {
            exec('sc stop SNMPWorker 2>&1', $out, $rc);
            $outStr = implode(' ', $out);
            $ok  = ($rc === 0
                || strpos($outStr, 'STOP_PENDING') !== false
                || strpos($outStr, 'STOPPED')      !== false);
            $msg = $ok
                ? 'Servis durduruldu.'
                : 'Servis durdurulamadı. Lütfen Yönetici yetkisiyle XAMPP çalıştırın.';
        } else {
            exec('systemctl stop snmp-worker 2>&1', $out, $rc);
            if ($rc !== 0) {
                exec('pkill -f "worker.py" 2>&1', $out2, $rc);
            }
            $ok  = ($rc === 0);
            $msg = $ok ? 'Servis durduruldu.' : 'Servis durdurulamadı.';
        }
        echo json_encode(['success' => $ok, 'message' => $msg]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Bilinmeyen işlem']);
}
exit();
