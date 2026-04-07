<?php
/**
 * Device Import API
 * Handles Excel bulk upload and device registry management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

// Initialize authentication
$auth = new Auth($conn);

// Get authenticated user
function getAuthenticatedUser($auth) {
    if ($auth->isLoggedIn()) {
        $user = $auth->getUser();
        return $user['username'] ?? 'system';
    }
    return 'system';
}

// Database connection (reuse existing connection from db.php)
function getDBConnection() {
    global $conn;
    if ($conn && $conn->ping()) {
        return $conn;
    }
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

// Normalize MAC address
function normalizeMac($mac) {
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    if (strlen($mac) === 12) {
        return implode(':', str_split($mac, 2));
    }
    return null;
}

// Validate IP address
function validateIP($ip) {
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}

/**
 * Read SMTP config from snmp_worker/config/config.yml and return parsed email settings.
 * Returns null if email is disabled or config unreadable.
 */
function getSmtpConfig() {
    $configPath = __DIR__ . '/../snmp_worker/config/config.yml';
    if (!file_exists($configPath)) return null;
    $content = @file_get_contents($configPath);
    if (!$content) return null;

    // Extract the email: block using regex (same approach as snmp_admin.php)
    if (!preg_match(
        '/email:\s+enabled:\s*(true|false)\s+smtp_host:\s*"([^"]*)"\s+smtp_port:\s*([^\s]+)\s+smtp_user:\s*"([^"]*)"\s+smtp_password:\s*"([^"]*)"\s+from_address:\s*"([^"]*)"/s',
        $content, $m
    )) return null;

    if ($m[1] !== 'true') return null; // email disabled

    // Parse to_addresses list
    $toAddresses = [];
    if (preg_match('/to_addresses:\s*((?:\s*-\s*"[^"]*"\s*)+)/', $content, $tm)) {
        preg_match_all('/-\s*"([^"]*)"/', $tm[1], $addrMatches);
        $toAddresses = array_filter($addrMatches[1]);
    }

    if (empty($toAddresses)) return null;

    return [
        'smtp_host'     => $m[2],
        'smtp_port'     => (int)$m[3],
        'smtp_user'     => $m[4],
        'smtp_password' => $m[5],
        'from_address'  => $m[6],
        'to_addresses'  => array_values($toAddresses),
    ];
}

/**
 * Send an alarm email via SMTP (sync, blocking).
 * Uses fsockopen for port 465 (SSL) or stream_socket_client + STARTTLS for port 587.
 *
 * @param string $subject
 * @param string $htmlBody
 * @return bool
 */
function sendAlarmEmail(string $subject, string $htmlBody): bool {
    $cfg = getSmtpConfig();
    if (!$cfg) return false;

    $boundary = md5(uniqid());
    $plainBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    $message = "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
        . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $plainBody . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $htmlBody . "\r\n"
        . "--{$boundary}--\r\n";

    $port    = $cfg['smtp_port'];
    $host    = $cfg['smtp_host'];
    $user    = $cfg['smtp_user'];
    $pass    = $cfg['smtp_password'];
    $from    = $cfg['from_address'] ?: $user;
    $tos     = $cfg['to_addresses'];

    try {
        // Implicit SSL on port 465
        $prefix = ($port === 465) ? 'ssl://' : '';
        $errno  = 0; $errstr = '';
        $sock = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
        if (!$sock) return false;

        $recv = fgets($sock, 512);
        if (substr($recv, 0, 3) !== '220') { fclose($sock); return false; }

        $cmds = [];
        $cmds[] = "EHLO " . gethostname();
        if ($port !== 465) {
            // STARTTLS for port 587
            $cmds[] = "STARTTLS";
        }
        $cmds[] = "AUTH LOGIN";
        $cmds[] = base64_encode($user);
        $cmds[] = base64_encode($pass);
        $cmds[] = "MAIL FROM:<{$from}>";
        foreach ($tos as $to) {
            $cmds[] = "RCPT TO:<{$to}>";
        }
        $cmds[] = "DATA";

        foreach ($cmds as $i => $cmd) {
            fputs($sock, $cmd . "\r\n");
            $r = fgets($sock, 512);
            // STARTTLS: enable crypto after server 220 response
            if ($port !== 465 && $cmd === 'STARTTLS') {
                if (substr($r, 0, 3) !== '220') { fclose($sock); return false; }
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($sock); return false;
                }
                // Re-send EHLO after STARTTLS
                fputs($sock, "EHLO " . gethostname() . "\r\n");
                while (($ehlo = fgets($sock, 512)) !== false && substr($ehlo, 3, 1) === '-') {}
            }
        }

        // Write email body
        $headers = "From: {$from}\r\nTo: " . implode(', ', $tos) . "\r\nSubject: {$subject}\r\nDate: " . date('r') . "\r\n";
        fputs($sock, $headers . $message . "\r\n.\r\n");
        $r = fgets($sock, 512);
        fputs($sock, "QUIT\r\n");
        fclose($sock);
        return substr($r, 0, 3) === '250';
    } catch (\Throwable $e) {
        error_log("sendAlarmEmail error: " . $e->getMessage());
        return false;
    }
}

// ── Lightweight native xlsx / csv reader (no vendor dependency) ──────────────

/**
 * Converts a column letter string (A, B, AA, ...) to a zero-based index.
 */
function _colLetterToIndex(string $col): int {
    $idx = 0;
    $len = strlen($col);
    for ($i = 0; $i < $len; $i++) {
        $idx = $idx * 26 + (ord($col[$i]) - 64);
    }
    return $idx - 1;
}

/**
 * Parse an xlsx file and return its first sheet as a 2-D array.
 * Requires ZipArchive and SimpleXML (both bundled with PHP).
 */
function parseXlsx(string $filePath): array {
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new \RuntimeException('xlsx dosyası açılamadı.');
    }

    // Shared strings table
    $sharedStrings = [];
    $ssRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssRaw !== false) {
        $ss = simplexml_load_string($ssRaw);
        foreach ($ss->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string) $si->t;
            } else {
                $text = '';
                foreach ($si->r as $r) {
                    $text .= (string) $r->t;
                }
                $sharedStrings[] = $text;
            }
        }
    }

    // First worksheet
    $sheetRaw = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetRaw === false) {
        throw new \RuntimeException('Çalışma sayfası okunamadı.');
    }

    $sheet = simplexml_load_string($sheetRaw);
    $rows  = [];

    foreach ($sheet->sheetData->row as $row) {
        $rowData  = [];
        $lastCol  = -1;

        foreach ($row->c as $cell) {
            if (!preg_match('/([A-Z]+)/', (string) $cell['r'], $m)) {
                continue;
            }
            $col = _colLetterToIndex($m[1]);

            // Fill any skipped (empty) columns
            for ($i = $lastCol + 1; $i < $col; $i++) {
                $rowData[] = null;
            }
            $lastCol = $col;

            $type  = (string) ($cell['t'] ?? '');
            $value = (string) ($cell->v ?? '');

            if ($type === 's') {
                $rowData[] = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $rowData[] = (string) ($cell->is->t ?? '');
            } elseif ($type === 'b') {
                $rowData[] = $value === '1';
            } else {
                $rowData[] = $value === '' ? null : $value;
            }
        }

        $rows[] = $rowData;
    }

    return $rows;
}

/**
 * Read an xlsx or csv file and return rows as a 2-D array (like PhpSpreadsheet toArray()).
 */
function readSpreadsheetFile(string $filePath, string $ext): array {
    if ($ext === 'csv') {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            // Detect and skip UTF-8 BOM
            $bom = fread($handle, 3);
            if (strlen($bom) < 3 || $bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }
        return $rows;
    }

    return parseXlsx($filePath);
}

// ── Lightweight native xlsx writer (no vendor dependency) ────────────────────

/**
 * Convert a zero-based column index to an Excel column letter (0→A, 25→Z, 26→AA …).
 */
function _colIndexToLetter(int $idx): string {
    $letter = '';
    $n = $idx + 1;
    while ($n > 0) {
        $rem    = ($n - 1) % 26;
        $letter = chr(65 + $rem) . $letter;
        $n      = (int)(($n - 1) / 26);
    }
    return $letter;
}

/**
 * Escape a string value for embedding in an XML attribute or element.
 */
function _xmlEsc(string $v): string {
    return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/**
 * Build a minimal, valid .xlsx binary string from a 2-D array.
 *
 * Row 0 is treated as the header row and rendered bold.
 * All values are written as inline strings (t="inlineStr") so no shared-strings
 * table is needed.  Requires ZipArchive (bundled with PHP).
 *
 * @param array $rows  2-D array [ [col0, col1, …], … ]
 * @return string      Raw binary content of the .xlsx file
 */
function buildXlsx(array $rows): string {
    // ── worksheet XML ─────────────────────────────────────────────────────────
    $sheetRows = '';
    foreach ($rows as $ri => $rowData) {
        $rowNum  = $ri + 1;
        $isHdr   = ($ri === 0);
        $cells   = '';
        foreach ($rowData as $ci => $val) {
            $colLetter = _colIndexToLetter($ci);
            $ref       = $colLetter . $rowNum;
            $safe      = _xmlEsc((string)($val ?? ''));
            // s="1" = bold style (defined in styles.xml); s="0" = normal
            $style     = $isHdr ? ' s="1"' : '';
            $cells    .= "<c r=\"{$ref}\" t=\"inlineStr\"{$style}><is><t>{$safe}</t></is></c>";
        }
        $sheetRows .= "<row r=\"{$rowNum}\">{$cells}</row>";
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetRows . '</sheetData>'
        . '</worksheet>';

    // ── styles.xml (2 xf: 0=normal, 1=bold header) ───────────────────────────
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="2">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        . '</fills>'
        . '<borders count="1">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2">'
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .   '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>';

    // ── workbook & relationship XML ───────────────────────────────────────────
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Kayıtlar" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $wbRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $dotRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';

    // ── assemble into a ZIP (xlsx = ZIP) ──────────────────────────────────────
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException('Geçici xlsx dosyası oluşturulamadı.');
    }
    $zip->addFromString('[Content_Types].xml',          $contentTypesXml);
    $zip->addFromString('_rels/.rels',                  $dotRelsXml);
    $zip->addFromString('xl/workbook.xml',              $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels',   $wbRelsXml);
    $zip->addFromString('xl/styles.xml',                $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml',     $sheetXml);
    $zip->close();

    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

// ─────────────────────────────────────────────────────────────────────────────

// Handle Excel file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    // Validate file
    $allowed_extensions = ['xlsx', 'csv'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed_extensions)) {
        echo json_encode(['error' => 'Geçersiz dosya türü. Lütfen xlsx veya csv dosyası yükleyin.']);
        exit;
    }
    
    try {
        $rows = readSpreadsheetFile($file['tmp_name'], $file_ext);
        
        // Get header row to detect format
        $header = array_shift($rows);
        
        // Detect format based on header row
        // New format: "IP Adresi" or similar in first column
        // Old format: "MAC" or similar in first column
        $is_new_format = false;
        
        if (!empty($header)) {
            $first_header = strtolower(trim($header[0] ?? ''));
            // Check if first column is IP-related (new format) or MAC-related (old format)
            if (strpos($first_header, 'ip') !== false || count($header) <= 3) {
                $is_new_format = true;
            }
        }
        
        $conn = getDBConnection();
        $success_count = 0;
        $error_count = 0;
        $errors = [];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO mac_device_registry 
                (mac_address, ip_address, device_name, user_name, location, department, notes, source, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'excel', ?)
                ON DUPLICATE KEY UPDATE
                    ip_address = VALUES(ip_address),
                    device_name = VALUES(device_name),
                    user_name = VALUES(user_name),
                    location = VALUES(location),
                    department = VALUES(department),
                    notes = VALUES(notes),
                    source = 'excel',
                    updated_by = VALUES(created_by)
            ");
            
            foreach ($rows as $index => $row) {
                $row_num = $index + 2; // +2 for header and 0-index
                
                // Parse based on detected format
                if ($is_new_format) {
                    // New simplified format: IP Adresi | Hostname | MAC Adresi
                    $ip = isset($row[0]) ? trim($row[0]) : null;
                    $device_name = isset($row[1]) ? trim($row[1]) : null;
                    $mac = isset($row[2]) ? normalizeMac($row[2]) : null;
                    $user_name = null;
                    $location = null;
                    $department = null;
                    $notes = null;
                } else {
                    // Old format: MAC, IP, Device Name, User, Location, Department, Notes
                    $mac = isset($row[0]) ? normalizeMac($row[0]) : null;
                    $ip = isset($row[1]) ? trim($row[1]) : null;
                    $device_name = isset($row[2]) ? trim($row[2]) : null;
                    $user_name = isset($row[3]) ? trim($row[3]) : null;
                    $location = isset($row[4]) ? trim($row[4]) : null;
                    $department = isset($row[5]) ? trim($row[5]) : null;
                    $notes = isset($row[6]) ? trim($row[6]) : null;
                }
                
                // Validate
                if (!$mac) {
                    $errors[] = "Row $row_num: Invalid MAC address";
                    $error_count++;
                    continue;
                }
                
                if ($ip && !validateIP($ip)) {
                    $errors[] = "Row $row_num: Invalid IP address ($ip)";
                    $error_count++;
                    continue;
                }
                
                if (!$device_name) {
                    $errors[] = "Row $row_num: Hostname is required";
                    $error_count++;
                    continue;
                }
                
                // Insert/Update
                $created_by = getAuthenticatedUser($auth);
                $stmt->bind_param(
                    'ssssssss',
                    $mac, $ip, $device_name, $user_name, 
                    $location, $department, $notes, $created_by
                );
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $errors[] = "Row $row_num: Database error - " . $stmt->error;
                    $error_count++;
                }
            }
            
            $stmt->close();
            
            // Log import history
            $stmt_history = $conn->prepare("
                INSERT INTO mac_device_import_history 
                (filename, total_rows, success_count, error_count, errors, imported_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $total_rows = count($rows);
            $errors_json = json_encode($errors);
            $imported_by = getAuthenticatedUser($auth);
            
            $stmt_history->bind_param(
                'siiiss',
                $file['name'], $total_rows, $success_count, $error_count, 
                $errors_json, $imported_by
            );
            $stmt_history->execute();
            $stmt_history->close();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Import completed",
                'total_rows' => $total_rows,
                'success_count' => $success_count,
                'error_count' => $error_count,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
        }
        
        $conn->close();
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to read file: ' . $e->getMessage()]);
    }
    
    exit;
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    $conn = getDBConnection();
    
    switch ($action) {
        case 'list':
            // List all devices with pagination and search
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 10;
            $offset = ($page - 1) * $limit;
            
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $where_parts = [];
            $params = [];
            $types  = '';
            
            if ($search) {
                $where_parts[] = "(mac_address LIKE ? OR device_name LIKE ? OR ip_address LIKE ?)";
                $search_param = "%$search%";
                $params = [$search_param, $search_param, $search_param];
                $types  = 'sss';
            }

            // Optional MAC-list filter (legacy, kept for backward-compat)
            $mac_filter_raw = isset($_GET['mac_filter']) ? trim($_GET['mac_filter']) : '';
            if ($mac_filter_raw !== '') {
                $mac_list = json_decode($mac_filter_raw, true);
                if (is_array($mac_list) && count($mac_list) > 0) {
                    // Sanitize: keep only valid MAC-looking strings
                    $safe_macs = array_values(array_filter($mac_list, function($m) {
                        return preg_match('/^[0-9A-Fa-f:]{11,17}$/', $m);
                    }));
                    if (count($safe_macs) > 0) {
                        $placeholders = implode(',', array_fill(0, count($safe_macs), '?'));
                        $where_parts[] = "mac_address IN ($placeholders)";
                        $params = array_merge($params, $safe_macs);
                        $types .= str_repeat('s', count($safe_macs));
                    } else {
                        // Filter requested but no valid MACs → return empty result set
                        $where_parts[] = "1=0";
                    }
                } elseif ($mac_filter_raw === '[]') {
                    // Empty array means "none match" → return empty
                    $where_parts[] = "1=0";
                }
            }

            // Status filter: uses match_status column (set by apply_to_ports action)
            $valid_statuses = ['updated', 'already_current', 'unmatched'];
            $status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
            if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
                $col_check = $conn->query("SHOW COLUMNS FROM mac_device_registry LIKE 'match_status'");
                if ($col_check && $col_check->num_rows > 0) {
                    $where_parts[] = "match_status = ?";
                    $params[]  = $status_filter;
                    $types    .= 's';
                }
            }

            $where = count($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';
            
            // Count total
            $count_query = "SELECT COUNT(*) as total FROM mac_device_registry $where";
            $stmt = $conn->prepare($count_query);
            if ($params) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $total = $row ? (int)$row['total'] : 0;
            $stmt->close();
            $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;
            
            // Get data
            $query = "
                SELECT * FROM mac_device_registry 
                $where
                ORDER BY updated_at DESC
                LIMIT ? OFFSET ?
            ";
            $stmt = $conn->prepare($query);
            if ($params) {
                $all_params = array_merge($params, [$limit, $offset]);
                $all_types  = $types . 'ii';
                $stmt->bind_param($all_types, ...$all_params);
            } else {
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $devices = [];
            while ($row = $result->fetch_assoc()) {
                $devices[] = $row;
            }
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => $totalPages,
                'devices' => $devices
            ]);
            break;
        
        case 'get':
            // Get single device
            $mac = isset($_GET['mac']) ? normalizeMac($_GET['mac']) : null;
            
            if (!$mac) {
                echo json_encode(['error' => 'MAC address required']);
                break;
            }
            
            $stmt = $conn->prepare("SELECT * FROM mac_device_registry WHERE mac_address = ?");
            $stmt->bind_param('s', $mac);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                echo json_encode(['success' => true, 'device' => $row]);
            } else {
                echo json_encode(['error' => 'Device not found']);
            }
            $stmt->close();
            break;
        
        case 'get_by_name':
            // Get single device by exact device_name
            $name = isset($_GET['name']) ? trim($_GET['name']) : null;
            
            if (!$name) {
                echo json_encode(['error' => 'Device name required']);
                break;
            }
            
            $stmt = $conn->prepare("SELECT * FROM mac_device_registry WHERE device_name = ? LIMIT 1");
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                echo json_encode(['success' => true, 'device' => $row]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Device not found']);
            }
            $stmt->close();
            break;

        case 'history':
            // Get import history
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            
            $stmt = $conn->prepare("
                SELECT * FROM mac_device_import_history 
                ORDER BY import_date DESC 
                LIMIT ?
            ");
            $stmt->bind_param('i', $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $history = [];
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
            $stmt->close();
            
            echo json_encode(['success' => true, 'history' => $history]);
            break;

        case 'export_xlsx':
            // Export mac_device_registry as .xlsx download (native PHP, no vendor)
            $rows = [['IP Adresi', 'Hostname', 'MAC Adresi', 'Kaynak', 'Güncelleme']];
            $res = $conn->query(
                "SELECT ip_address, device_name, mac_address, source, updated_at
                 FROM mac_device_registry
                 ORDER BY updated_at DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = [
                        $row['ip_address'] ?? '',
                        $row['device_name'] ?? '',
                        $row['mac_address'] ?? '',
                        $row['source'] ?? '',
                        $row['updated_at'] ?? '',
                    ];
                }
            }
            $xlsxContent = buildXlsx($rows);
            $filename = 'device_registry_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Content-Length: ' . strlen($xlsxContent));
            echo $xlsxContent;
            $conn->close();
            exit;

        case 'export_csv':
            // Export mac_device_registry as CSV download
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="device_registry_' . date('Ymd_His') . '.csv"');
            header('Cache-Control: no-cache, must-revalidate');
            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, ['IP Adresi', 'Hostname', 'MAC Adresi', 'Kaynak', 'Güncelleme']);
            $res = $conn->query(
                "SELECT ip_address, device_name, mac_address, source, updated_at
                 FROM mac_device_registry
                 ORDER BY updated_at DESC"
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    fputcsv($out, [
                        $row['ip_address'] ?? '',
                        $row['device_name'] ?? '',
                        $row['mac_address'] ?? '',
                        $row['source'] ?? '',
                        $row['updated_at'] ?? '',
                    ]);
                }
            }
            fclose($out);
            $conn->close();
            exit;

        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    
    $conn->close();
    exit;
}

// Handle POST - Manual entry and other actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['excel_file'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    // Check for action in query params first, then in JSON body
    $action = $_GET['action'] ?? ($data['action'] ?? '');

    // Allow list action via POST (used when mac_filter list is too large for a GET URL)
    if ($action === 'list') {
        $conn = getDBConnection();
        $page   = isset($data['page'])   ? max(1, (int)$data['page'])            : 1;
        $limit  = isset($data['limit'])  ? max(1, min(100, (int)$data['limit']))  : 10;
        $offset = ($page - 1) * $limit;
        $search = isset($data['search']) ? trim($data['search'])                  : '';

        $where_parts = [];
        $params = [];
        $types  = '';

        if ($search) {
            $where_parts[] = "(mac_address LIKE ? OR device_name LIKE ? OR ip_address LIKE ?)";
            $search_param  = "%$search%";
            $params        = [$search_param, $search_param, $search_param];
            $types         = 'sss';
        }

        $mac_filter = isset($data['mac_filter']) ? $data['mac_filter'] : null;
        if (is_array($mac_filter)) {
            if (count($mac_filter) === 0) {
                $where_parts[] = "1=0";
            } else {
                $safe_macs = array_values(array_filter($mac_filter, function($m) {
                    return preg_match('/^[0-9A-Fa-f:]{11,17}$/', $m);
                }));
                if (count($safe_macs) > 0) {
                    $placeholders  = implode(',', array_fill(0, count($safe_macs), '?'));
                    $where_parts[] = "mac_address IN ($placeholders)";
                    $params        = array_merge($params, $safe_macs);
                    $types        .= str_repeat('s', count($safe_macs));
                } else {
                    $where_parts[] = "1=0";
                }
            }
        }

        $where = count($where_parts) ? 'WHERE ' . implode(' AND ', $where_parts) : '';

        $count_query = "SELECT COUNT(*) as total FROM mac_device_registry $where";
        $stmt = $conn->prepare($count_query);
        if ($params) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $total = $row ? (int)$row['total'] : 0;
        $stmt->close();

        $totalPages = $total > 0 ? ceil($total / $limit) : 1;

        $query = "SELECT * FROM mac_device_registry $where ORDER BY updated_at DESC LIMIT ? OFFSET ?";
        $stmt  = $conn->prepare($query);
        if ($params) {
            $all_params = array_merge($params, [$limit, $offset]);
            $stmt->bind_param($types . 'ii', ...$all_params);
        } else {
            $stmt->bind_param('ii', $limit, $offset);
        }
        $stmt->execute();
        $result  = $stmt->get_result();
        $devices = [];
        while ($row = $result->fetch_assoc()) { $devices[] = $row; }
        $stmt->close();

        echo json_encode([
            'success'    => true,
            'total'      => $total,
            'page'       => $page,
            'limit'      => $limit,
            'totalPages' => $totalPages,
            'devices'    => $devices,
        ]);
        exit;
    }

    if ($action === 'manual_add') {
        $ip = isset($data['ip_address']) ? trim($data['ip_address']) : null;
        $hostname = isset($data['hostname']) ? trim($data['hostname']) : null;
        $mac = isset($data['mac_address']) ? normalizeMac($data['mac_address']) : null;
        
        // Validate inputs
        $errors = [];
        
        if (!$mac) {
            $errors[] = 'Invalid MAC address format';
        }
        
        if (!$ip || !validateIP($ip)) {
            $errors[] = 'Invalid IP address format';
        }
        
        if (!$hostname) {
            $errors[] = 'Hostname is required';
        }
        
        if (count($errors) > 0) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }
        
        $conn = getDBConnection();
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO mac_device_registry 
                (mac_address, ip_address, device_name, source, created_by)
                VALUES (?, ?, ?, 'manual', ?)
                ON DUPLICATE KEY UPDATE
                    ip_address = VALUES(ip_address),
                    device_name = VALUES(device_name),
                    source = 'manual',
                    updated_by = VALUES(created_by)
            ");
            
            $created_by = getAuthenticatedUser($auth);
            $stmt->bind_param('ssss', $mac, $ip, $hostname, $created_by);
            
            if ($stmt->execute()) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Device added successfully',
                    'device' => [
                        'mac_address' => $mac,
                        'ip_address' => $ip,
                        'device_name' => $hostname
                    ]
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $stmt->error]);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        $conn->close();
        exit;
    }
    
    // Update existing device
    if ($action === 'update') {
        $originalMac = isset($data['original_mac']) ? normalizeMac($data['original_mac']) : null;
        $newMac = isset($data['mac_address']) ? normalizeMac($data['mac_address']) : null;
        $ip = isset($data['ip_address']) ? trim($data['ip_address']) : null;
        $hostname = isset($data['device_name']) ? trim($data['device_name']) : null;
        
        // Validate inputs
        $errors = [];
        
        if (!$originalMac) {
            $errors[] = 'Original MAC address is required';
        }
        
        if (!$newMac) {
            $errors[] = 'Invalid new MAC address format';
        }
        
        if ($ip && !validateIP($ip)) {
            $errors[] = 'Invalid IP address format';
        }
        
        if (!$hostname) {
            $errors[] = 'Hostname is required';
        }
        
        if (count($errors) > 0) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit;
        }
        
        $conn = getDBConnection();
        
        try {
            $conn->begin_transaction();

            // If MAC changed, delete old record and insert new one
            if ($originalMac !== $newMac) {
                // Delete old record
                $stmt = $conn->prepare("DELETE FROM mac_device_registry WHERE mac_address = ?");
                $stmt->bind_param('s', $originalMac);
                $stmt->execute();
                $stmt->close();
            }
            
            // Insert or update with new data
            $stmt = $conn->prepare("
                INSERT INTO mac_device_registry 
                (mac_address, ip_address, device_name, source, created_by)
                VALUES (?, ?, ?, 'manual', ?)
                ON DUPLICATE KEY UPDATE
                    ip_address = VALUES(ip_address),
                    device_name = VALUES(device_name),
                    source = 'manual',
                    updated_by = VALUES(created_by)
            ");
            
            $updated_by = getAuthenticatedUser($auth);
            $stmt->bind_param('ssss', $newMac, $ip, $hostname, $updated_by);
            
            if ($stmt->execute()) {
                $conn->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Device updated successfully',
                    'device' => [
                        'mac_address' => $newMac,
                        'ip_address' => $ip,
                        'device_name' => $hostname
                    ]
                ]);
            } else {
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $stmt->error]);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        $conn->close();
        exit;
    }
    
    // Handle sync_and_scan action — runs apply_to_ports then scan_unregistered in one call.
    // This is the combined "Senkronize Et & Tara" button action and also used by the
    // hourly auto-run on the Device Import page.
    if ($action === 'sync_and_scan') {
        $conn = getDBConnection();

        try {
            // ── Phase 1: apply_to_ports ──────────────────────────────────────
            $apply_updated_count      = 0;
            $apply_updated_macs       = [];
            $apply_already_current    = [];
            $apply_unmatched_macs     = [];

            // Load MACs that were previously matched (ever seen by SNMP) so
            // that temporarily-offline devices are not re-labelled as unmatched.
            // Only MACs that have NEVER been seen by SNMP should get 'unmatched'.
            $prevMatchedSet = [];
            $prevColCheck = $conn->query("SHOW COLUMNS FROM mac_device_registry LIKE 'match_status'");
            if ($prevColCheck && $prevColCheck->num_rows > 0) {
                $prevRes = $conn->query("SELECT mac_address FROM mac_device_registry WHERE match_status IN ('updated', 'already_current')");
                if ($prevRes) {
                    while ($r = $prevRes->fetch_assoc()) {
                        $prevMatchedSet[strtolower(str_replace(':', '', $r['mac_address']))] = true;
                    }
                    $prevRes->close();
                }
            }

            $regResult = $conn->query("
                SELECT mac_address, ip_address, device_name
                FROM mac_device_registry
                WHERE mac_address IS NOT NULL
                  AND ip_address IS NOT NULL
                  AND device_name IS NOT NULL
                  AND (source IS NULL OR source != 'snmp_hub_auto')
            ");

            if ($regResult) {
                while ($device = $regResult->fetch_assoc()) {
                    $mac          = $device['mac_address'];
                    $ip           = $device['ip_address'];
                    $hostname     = $device['device_name'];
                    if (empty($ip) || !validateIP($ip)) { continue; }
                    $macNormalized = strtolower(str_replace(':', '', $mac));

                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) as cnt FROM ports
                        WHERE FIND_IN_SET(?, LOWER(REPLACE(mac, ':', '')))
                        AND mac IS NOT NULL AND mac != ''
                    ");
                    $portExists = false;
                    if ($checkStmt) {
                        $checkStmt->bind_param('s', $macNormalized);
                        $checkStmt->execute();
                        $portExists = ($checkStmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
                        $checkStmt->close();
                    }

                    $trackingPortId = null;
                    if (!$portExists) {
                        $macHex = strtoupper(str_replace(':', '', $mac));
                        $tStmt  = $conn->prepare("
                            SELECT p.id
                            FROM mac_address_tracking mat
                            JOIN snmp_devices sd ON sd.id = mat.current_device_id
                            JOIN switches sw ON sw.name = sd.name
                            JOIN ports p ON p.switch_id = sw.id
                                         AND p.port_no = mat.current_port_number
                            WHERE UPPER(REPLACE(mat.mac_address, ':', '')) = ?
                            LIMIT 1
                        ");
                        if ($tStmt) {
                            $tStmt->bind_param('s', $macHex);
                            $tStmt->execute();
                            $tRow = $tStmt->get_result()->fetch_assoc();
                            if ($tRow) { $portExists = true; $trackingPortId = (int)$tRow['id']; }
                            $tStmt->close();
                        }
                    }

                    if (!$portExists) {
                        // If this MAC was seen by SNMP in a previous run, it is
                        // temporarily offline — preserve its matched status rather
                        // than marking it unmatched. Only devices that have NEVER
                        // been matched should receive the 'unmatched' label.
                        if (isset($prevMatchedSet[$macNormalized])) {
                            $apply_already_current[] = $mac;
                        } else {
                            $apply_unmatched_macs[] = $mac;
                        }
                    }

                    if ($trackingPortId !== null) {
                        $updStmt = $conn->prepare("
                            UPDATE ports
                            SET ip = ?, device = ?,
                                mac = CASE WHEN (mac IS NULL OR mac = '') THEN ? ELSE CONCAT(mac, ',', ?) END
                            WHERE id = ?
                              AND NOT FIND_IN_SET(?, LOWER(REPLACE(COALESCE(mac,''), ':', '')))
                        ");
                        if ($updStmt) {
                            $macColon = $mac;
                            $updStmt->bind_param('ssssis', $ip, $hostname, $macColon, $macColon, $trackingPortId, $macNormalized);
                            $updStmt->execute();
                            if ($updStmt->affected_rows > 0) {
                                $apply_updated_macs[]  = $mac;
                                $apply_updated_count  += $updStmt->affected_rows;
                            } else { $apply_already_current[] = $mac; }
                            $updStmt->close();
                        } else { $apply_already_current[] = $mac; }
                    } else {
                        $updateStmt = $conn->prepare("
                            UPDATE ports SET ip = ?, device = ?
                            WHERE FIND_IN_SET(?, LOWER(REPLACE(mac, ':', '')))
                            AND mac IS NOT NULL AND mac != ''
                        ");
                        if ($updateStmt) {
                            $updateStmt->bind_param('sss', $ip, $hostname, $macNormalized);
                            $updateStmt->execute();
                            if ($portExists) {
                                if ($updateStmt->affected_rows > 0) {
                                    $apply_updated_macs[] = $mac;
                                    $apply_updated_count += $updateStmt->affected_rows;
                                } else { $apply_already_current[] = $mac; }
                            }
                            $updateStmt->close();
                        }
                    }
                }
                $regResult->close();

                // Persist match_status
                $colCheck = $conn->query("SHOW COLUMNS FROM mac_device_registry LIKE 'match_status'");
                if ($colCheck && $colCheck->num_rows > 0) {
                    $conn->query("UPDATE mac_device_registry SET match_status = NULL WHERE match_status IS NOT NULL");
                    $statusBatch = [
                        'updated'         => array_values(array_unique($apply_updated_macs)),
                        'already_current' => array_values(array_unique($apply_already_current)),
                        'unmatched'       => array_values(array_unique($apply_unmatched_macs)),
                    ];
                    foreach ($statusBatch as $statusVal => $macList) {
                        foreach ($macList as $m) {
                            $stStmt = $conn->prepare("UPDATE mac_device_registry SET match_status = ? WHERE mac_address = ?");
                            if ($stStmt) { $stStmt->bind_param('ss', $statusVal, $m); $stStmt->execute(); $stStmt->close(); }
                        }
                    }
                }
            }

            // ── Phase 2: scan_unregistered ───────────────────────────────────
            $scan_total       = 0;
            $scan_created     = 0;
            $scan_skipped     = 0;
            $scan_ports_upd   = 0;

            $scanResult = $conn->query("
                SELECT mat.mac_address,
                       mat.current_device_id,
                       mat.current_port_number,
                       mat.device_name,
                       mat.ip_address
                FROM mac_address_tracking mat
                LEFT JOIN mac_device_registry mdr
                       ON UPPER(REPLACE(mdr.mac_address, ':', '')) COLLATE utf8mb4_general_ci
                        = UPPER(REPLACE(mat.mac_address, ':', '')) COLLATE utf8mb4_general_ci
                WHERE mdr.mac_address IS NULL
                  AND mat.current_device_id IS NOT NULL
                  AND mat.last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY mat.last_seen DESC
            ");

            if ($scanResult) {
                $candidates = [];
                while ($row = $scanResult->fetch_assoc()) {
                    $candidates[] = [
                        'mac'      => strtoupper(trim($row['mac_address'])),
                        'deviceId' => intval($row['current_device_id']),
                        'portNo'   => intval($row['current_port_number']),
                        'devName'  => $row['device_name'] ?? '',
                        'ip'       => $row['ip_address'] ?? '',
                    ];
                }
                $scanResult->close();

                $scan_total = count($candidates);

                if ($scan_total > 0) {
                    $macList      = array_column($candidates, 'mac');
                    $placeholders = implode(',', array_fill(0, count($macList), '?'));
                    $types        = str_repeat('s', count($macList));
                    $activeStmt   = $conn->prepare("
                        SELECT new_value FROM alarms
                        WHERE alarm_type = 'mac_added' AND status = 'ACTIVE'
                          AND new_value IN ($placeholders)
                    ");
                    $alreadyActive = [];
                    if ($activeStmt) {
                        $activeStmt->bind_param($types, ...$macList);
                        $activeStmt->execute();
                        $activeRes = $activeStmt->get_result();
                        while ($r = $activeRes->fetch_row()) { $alreadyActive[$r[0]] = true; }
                        $activeStmt->close();
                    }

                    $insStmt = $conn->prepare("
                        INSERT INTO alarms
                            (device_id, port_number, alarm_type, severity, status,
                             title, message, old_value, new_value,
                             first_occurrence, last_occurrence, occurrence_count,
                             created_at, updated_at)
                        VALUES (?, ?, 'mac_added', 'MEDIUM', 'ACTIVE',
                                ?, ?, '', ?,
                                NOW(), NOW(), 1, NOW(), NOW())
                    ");
                    $portIpStmt = $conn->prepare("
                        UPDATE ports SET ip = ?
                        WHERE LOWER(REPLACE(mac, ':', '')) = ?
                          AND mac IS NOT NULL AND mac != ''
                          AND (ip IS NULL OR ip = '')
                    ");

                    foreach ($candidates as $c) {
                        $mac     = $c['mac'];
                        $devId   = $c['deviceId'];
                        $portNo  = $c['portNo'];
                        $devName = $c['devName'];
                        $ip      = $c['ip'];

                        if (!empty($ip) && validateIP($ip) && $portIpStmt) {
                            $macNorm = strtolower(str_replace(':', '', $mac));
                            $portIpStmt->bind_param('ss', $ip, $macNorm);
                            $portIpStmt->execute();
                            if ($portIpStmt->affected_rows > 0) { $scan_ports_upd++; }
                        }

                        if (isset($alreadyActive[$mac])) { $scan_skipped++; continue; }

                        $title   = "Kayıtsız MAC: $mac";
                        $message = "Port $portNo üzerinde Device Import kaydı olmayan MAC tespit edildi: $mac" .
                                   ($ip ? " (IP: $ip)" : '') .
                                   ($devName ? " (Cihaz: $devName)" : '') .
                                   ". Cihazı kaydetmek için Device Import sayfasını kullanın.";
                        if ($insStmt) {
                            $insStmt->bind_param('iisss', $devId, $portNo, $title, $message, $mac);
                            if ($insStmt->execute()) { $scan_created++; }
                        }
                    }
                    if ($insStmt)    { $insStmt->close(); }
                    if ($portIpStmt) { $portIpStmt->close(); }
                }
            }

            // ── Phase 3: Send email notification if new alarms were created ──
            if ($scan_created > 0) {
                $subject = "[MEDIUM] Kayıtsız MAC Alarmı — {$scan_created} yeni alarm oluşturuldu";
                $htmlBody = <<<HTML
<!DOCTYPE html><html><head>
<style>body{font-family:Arial,sans-serif;}
.header{background:#f59e0b;color:#fff;padding:16px;}
.content{padding:16px;}
.info{background:#fffbeb;padding:12px;border-left:4px solid #f59e0b;margin-bottom:12px;}
.footer{font-size:12px;color:#6c757d;padding:12px;}
</style></head><body>
<div class="header"><h2>Kayıtsız MAC Alarm Özeti</h2></div>
<div class="content">
<div class="info">
<p><strong>Tarama sonucu:</strong> {$scan_total} kayıtsız MAC tespit edildi.</p>
<p><strong>Oluşturulan alarmlar:</strong> {$scan_created}</p>
<p><strong>Atlanan (mevcut aktif alarm):</strong> {$scan_skipped}</p>
</div>
<p>Kayıtsız cihazları kayıt altına almak için <em>Device Import</em> sayfasını ziyaret edin.</p>
</div>
<div class="footer"><p>SNMP Worker — Network Monitoring System</p></div>
</body></html>
HTML;
                sendAlarmEmail($subject, $htmlBody);
            }

            echo json_encode([
                'success'              => true,
                // apply_to_ports results
                'updated_count'        => $apply_updated_count,
                'updated_macs'         => array_values(array_unique($apply_updated_macs)),
                'already_current_macs' => array_values(array_unique($apply_already_current)),
                'matched_macs'         => array_values(array_unique(array_merge($apply_updated_macs, $apply_already_current))),
                'unmatched_macs'       => array_values(array_unique($apply_unmatched_macs)),
                // scan_unregistered results
                'total_unregistered'   => $scan_total,
                'alarms_created'       => $scan_created,
                'alarms_skipped'       => $scan_skipped,
                'ports_updated'        => $scan_ports_upd,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Handle scan_unregistered action — find active MACs not in Device Import and alarm them
    if ($action === 'scan_unregistered') {
        $conn = getDBConnection();

        try {
            // Find MACs that appeared on a port in the last 24 hours
            // and are NOT present in mac_device_registry.
            // We use mac_address_tracking as the authoritative "currently active" source.
            // COLLATE utf8mb4_general_ci is applied to both sides to resolve the
            // collation mismatch between mac_address_tracking (utf8mb4_general_ci)
            // and mac_device_registry (utf8mb4_unicode_ci).
            $result = $conn->query("
                SELECT mat.mac_address,
                       mat.current_device_id,
                       mat.current_port_number,
                       mat.device_name,
                       mat.ip_address
                FROM mac_address_tracking mat
                LEFT JOIN mac_device_registry mdr
                       ON UPPER(REPLACE(mdr.mac_address, ':', '')) COLLATE utf8mb4_general_ci
                        = UPPER(REPLACE(mat.mac_address, ':', '')) COLLATE utf8mb4_general_ci
                WHERE mdr.mac_address IS NULL
                  AND mat.current_device_id IS NOT NULL
                  AND mat.last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY mat.last_seen DESC
            ");

            if (!$result) {
                echo json_encode(['success' => false, 'error' => 'Veritabanı sorgusu başarısız: ' . $conn->error]);
                exit;
            }

            // Collect all candidate rows first
            $candidates = [];
            while ($row = $result->fetch_assoc()) {
                $candidates[] = [
                    'mac'      => strtoupper(trim($row['mac_address'])),
                    'deviceId' => intval($row['current_device_id']),
                    'portNo'   => intval($row['current_port_number']),
                    'devName'  => $row['device_name'] ?? '',
                    'ip'       => $row['ip_address'] ?? '',
                ];
            }
            $result->close();

            $total_unregistered = count($candidates);
            $alarms_created     = 0;
            $alarms_skipped     = 0;
            $ports_updated      = 0;

            if ($total_unregistered > 0) {
                // Single query to find which of these MACs already have an ACTIVE mac_added alarm.
                $macList   = array_column($candidates, 'mac');
                $placeholders = implode(',', array_fill(0, count($macList), '?'));
                $types        = str_repeat('s', count($macList));
                $activeStmt   = $conn->prepare("
                    SELECT new_value FROM alarms
                    WHERE alarm_type = 'mac_added'
                      AND status = 'ACTIVE'
                      AND new_value IN ($placeholders)
                ");
                $activeStmt->bind_param($types, ...$macList);
                $activeStmt->execute();
                $activeRes = $activeStmt->get_result();
                $alreadyActive = [];
                while ($r = $activeRes->fetch_row()) {
                    $alreadyActive[$r[0]] = true;
                }
                $activeStmt->close();

                // Insert alarms only for MACs without an existing active alarm.
                // Also update ports.ip when mac_address_tracking has a valid IP for this MAC.
                $insStmt = $conn->prepare("
                    INSERT INTO alarms
                        (device_id, port_number, alarm_type, severity, status,
                         title, message, old_value, new_value,
                         first_occurrence, last_occurrence, occurrence_count,
                         created_at, updated_at)
                    VALUES (?, ?, 'mac_added', 'MEDIUM', 'ACTIVE',
                            ?, ?, '', ?,
                            NOW(), NOW(), 1, NOW(), NOW())
                ");

                $portIpStmt = $conn->prepare("
                    UPDATE ports
                    SET ip = ?
                    WHERE LOWER(REPLACE(mac, ':', '')) = ?
                      AND mac IS NOT NULL
                      AND mac != ''
                      AND (ip IS NULL OR ip = '')
                ");

                foreach ($candidates as $c) {
                    $mac     = $c['mac'];
                    $devId   = $c['deviceId'];
                    $portNo  = $c['portNo'];
                    $devName = $c['devName'];
                    $ip      = $c['ip'];

                    // Write IP to ports table if mac_address_tracking has a valid one
                    // and the port doesn't already have an IP set.
                    if (!empty($ip) && validateIP($ip) && $portIpStmt) {
                        $macNorm = strtolower(str_replace(':', '', $mac));
                        $portIpStmt->bind_param('ss', $ip, $macNorm);
                        $portIpStmt->execute();
                        if ($portIpStmt->affected_rows > 0) {
                            $ports_updated++;
                        }
                    }

                    if (isset($alreadyActive[$mac])) {
                        $alarms_skipped++;
                        continue;
                    }

                    $title   = "Kayıtsız MAC: $mac";
                    $message = "Port $portNo üzerinde Device Import kaydı olmayan MAC tespit edildi: $mac" .
                               ($ip ? " (IP: $ip)" : '') .
                               ($devName ? " (Cihaz: $devName)" : '') .
                               ". Cihazı kaydetmek için Device Import sayfasını kullanın.";

                    $insStmt->bind_param('iisss', $devId, $portNo, $title, $message, $mac);
                    if ($insStmt->execute()) {
                        $alarms_created++;
                    }
                }
                $insStmt->close();
                if ($portIpStmt) {
                    $portIpStmt->close();
                }
            }

            // Send email notification if new alarms were created
            if ($alarms_created > 0) {
                $subject = "[MEDIUM] Kayıtsız MAC Alarmı — {$alarms_created} yeni alarm oluşturuldu";
                $htmlBody = <<<HTML
<!DOCTYPE html><html><head>
<style>body{font-family:Arial,sans-serif;}
.header{background:#f59e0b;color:#fff;padding:16px;}
.content{padding:16px;}
.info{background:#fffbeb;padding:12px;border-left:4px solid #f59e0b;margin-bottom:12px;}
.footer{font-size:12px;color:#6c757d;padding:12px;}
</style></head><body>
<div class="header"><h2>Kayıtsız MAC Alarm Özeti</h2></div>
<div class="content">
<div class="info">
<p><strong>Tarama sonucu:</strong> {$total_unregistered} kayıtsız MAC tespit edildi.</p>
<p><strong>Oluşturulan alarmlar:</strong> {$alarms_created}</p>
<p><strong>Atlanan (mevcut aktif alarm):</strong> {$alarms_skipped}</p>
</div>
<p>Kayıtsız cihazları kayıt altına almak için <em>Device Import</em> sayfasını ziyaret edin.</p>
</div>
<div class="footer"><p>SNMP Worker — Network Monitoring System</p></div>
</body></html>
HTML;
                sendAlarmEmail($subject, $htmlBody);
            }

            echo json_encode([
                'success'            => true,
                'total_unregistered' => $total_unregistered,
                'alarms_created'     => $alarms_created,
                'alarms_skipped'     => $alarms_skipped,
                'ports_updated'      => $ports_updated,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }

        $conn->close();
        exit;
    }

    // Handle apply_to_ports action
    if ($action === 'apply_to_ports') {
        $conn = getDBConnection();
        
        try {
            // Get all devices from registry.
            // Exclude snmp_hub_auto entries: those have VLAN labels (e.g. "50", "JACKPOT")
            // as placeholders, not real IP addresses or hostnames. Writing them to the
            // ports table would corrupt the port display with VLAN-name strings.
            // Only excel/manual entries (real user-supplied data) should be applied.
            $result = $conn->query("
                SELECT mac_address, ip_address, device_name 
                FROM mac_device_registry 
                WHERE mac_address IS NOT NULL 
                AND ip_address IS NOT NULL 
                AND device_name IS NOT NULL
                AND (source IS NULL OR source != 'snmp_hub_auto')
            ");
            
            if (!$result) {
                echo json_encode(['success' => false, 'error' => 'Database query failed']);
                exit;
            }
            
            $updated_count = 0;
            $updated_macs = [];
            $already_current_macs = [];
            $unmatched_macs = [];

            // Load MACs that were previously matched (ever seen by SNMP) so
            // that temporarily-offline devices are not re-labelled as unmatched.
            // Only MACs that have NEVER been seen by SNMP should get 'unmatched'.
            $prevMatchedSet = [];
            $prevColCheck2 = $conn->query("SHOW COLUMNS FROM mac_device_registry LIKE 'match_status'");
            if ($prevColCheck2 && $prevColCheck2->num_rows > 0) {
                $prevRes2 = $conn->query("SELECT mac_address FROM mac_device_registry WHERE match_status IN ('updated', 'already_current')");
                if ($prevRes2) {
                    while ($r2 = $prevRes2->fetch_assoc()) {
                        $prevMatchedSet[strtolower(str_replace(':', '', $r2['mac_address']))] = true;
                    }
                    $prevRes2->close();
                }
            }
            
            // For each device, find and update matching ports
            while ($device = $result->fetch_assoc()) {
                $mac = $device['mac_address'];
                $ip = $device['ip_address'];
                $hostname = $device['device_name'];

                // Skip entries where ip_address is not a valid IP address.
                // VLAN labels (e.g. "50", "JACKPOT") may appear as placeholders
                // in registry entries whose source was previously 'snmp_hub_auto'
                // or that were imported with non-IP values.  Writing such strings
                // to ports.ip corrupts the hub-port display in the UI.
                if (empty($ip) || !validateIP($ip)) {
                    continue;
                }

                // Normalize MAC for comparison (remove colons, lowercase)
                $macNormalized = strtolower(str_replace(':', '', $mac));

                // ── Primary check: MAC present in ports.mac ──────────────────
                // Hub-switch ports store multiple MACs comma-separated
                // (e.g. "aa:bb:cc:11:11:11,dd:ee:ff:22:22:22"), so we use
                // FIND_IN_SET against the colon-stripped, lower-cased value
                // instead of an exact equality check.
                $checkStmt = $conn->prepare("
                    SELECT COUNT(*) as cnt FROM ports
                    WHERE FIND_IN_SET(?, LOWER(REPLACE(mac, ':', '')))
                    AND mac IS NOT NULL
                    AND mac != ''
                ");
                $portExists = false;
                if ($checkStmt) {
                    $checkStmt->bind_param('s', $macNormalized);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $portExists = ($checkResult->fetch_assoc()['cnt'] ?? 0) > 0;
                    $checkStmt->close();
                }

                // ── Fallback: MAC tracked by SNMP but aged out of FDB ────────
                // Devices behind hub/unmanaged switches are tracked in
                // mac_address_tracking individually.  When a MAC ages out of
                // the switch FDB (typically after 300 s of inactivity), the
                // SNMP worker replaces ports.mac with only the still-visible
                // MACs, causing the FIND_IN_SET above to fail even though the
                // device IS physically connected and was recently seen.
                // We fall back to mac_address_tracking to detect these cases.
                $trackingPortId = null;
                if (!$portExists) {
                    $macHex = strtoupper(str_replace(':', '', $mac));
                    $tStmt = $conn->prepare("
                        SELECT p.id
                        FROM mac_address_tracking mat
                        JOIN snmp_devices sd ON sd.id = mat.current_device_id
                        JOIN switches sw ON sw.name = sd.name
                        JOIN ports p ON p.switch_id = sw.id
                                     AND p.port_no = mat.current_port_number
                        WHERE UPPER(REPLACE(mat.mac_address, ':', '')) = ?
                        LIMIT 1
                    ");
                    if ($tStmt) {
                        $tStmt->bind_param('s', $macHex);
                        $tStmt->execute();
                        $tResult = $tStmt->get_result();
                        if ($tRow = $tResult->fetch_assoc()) {
                            $portExists    = true;
                            $trackingPortId = (int)$tRow['id'];
                        }
                        $tStmt->close();
                    }
                }

                if (!$portExists) {
                    // If this MAC was seen by SNMP in a previous run, it is
                    // temporarily offline — preserve its matched status rather
                    // than marking it unmatched. Only devices that have NEVER
                    // been matched should receive the 'unmatched' label.
                    if (isset($prevMatchedSet[$macNormalized])) {
                        $already_current_macs[] = $mac;
                    } else {
                        $unmatched_macs[] = $mac;
                    }
                }

                // ── Update ports table ────────────────────────────────────────
                if ($trackingPortId !== null) {
                    // MAC found via mac_address_tracking (not currently in
                    // ports.mac).  Update the port's device label and IP by ID,
                    // and append the MAC to ports.mac so future FIND_IN_SET
                    // checks work without requiring another tracking lookup.
                    $updStmt = $conn->prepare("
                        UPDATE ports
                        SET ip     = ?,
                            device = ?,
                            mac    = CASE
                                         WHEN (mac IS NULL OR mac = '') THEN ?
                                         ELSE CONCAT(mac, ',', ?)
                                     END
                        WHERE id = ?
                          AND NOT FIND_IN_SET(?, LOWER(REPLACE(COALESCE(mac,''), ':', '')))
                    ");
                    if ($updStmt) {
                        $macColon = $mac; // original colon-separated format
                        $updStmt->bind_param('ssssis', $ip, $hostname, $macColon, $macColon, $trackingPortId, $macNormalized);
                        $updStmt->execute();
                        $rowsAffected = $updStmt->affected_rows;
                        if ($rowsAffected > 0) {
                            $updated_macs[]  = $mac;
                            $updated_count  += $rowsAffected;
                        } else {
                            $already_current_macs[] = $mac;
                        }
                        $updStmt->close();
                    } else {
                        $already_current_macs[] = $mac;
                    }
                } else {
                    // Update ports table (same as Port Edit uses).
                    // Use FIND_IN_SET for the WHERE clause so that hub-switch
                    // ports (which store all connected device MACs
                    // comma-separated) are matched correctly alongside
                    // single-MAC ports.
                    // NOTE: connection_info is intentionally NOT updated here —
                    // it stores panel connection JSON (written by saveportwithpanel.php /
                    // paneltoswitchconnection.php) and must not be overwritten with a
                    // plain hostname string from the MAC registry.
                    $updateStmt = $conn->prepare("
                        UPDATE ports 
                        SET ip = ?, 
                            device = ?
                        WHERE FIND_IN_SET(?, LOWER(REPLACE(mac, ':', '')))
                        AND mac IS NOT NULL 
                        AND mac != ''
                    ");
                    if ($updateStmt) {
                        $updateStmt->bind_param('sss', $ip, $hostname, $macNormalized);
                        $updateStmt->execute();
                        $rowsAffected = $updateStmt->affected_rows;
                        if ($portExists) {
                            if ($rowsAffected > 0) {
                                $updated_macs[]  = $mac;
                                $updated_count  += $rowsAffected;
                            } else {
                                $already_current_macs[] = $mac;
                            }
                        }
                        $updateStmt->close();
                    }
                }
            }
            
            $result->close();

            // Persist match_status in the DB so the filter buttons can query it
            // without sending large MAC lists over the wire.
            // The match_status column is added by migration 68 in update_database.php.
            // If it exists, update it; if not, skip silently (filter will show all).
            $colCheck = $conn->query("SHOW COLUMNS FROM mac_device_registry LIKE 'match_status'");
            if ($colCheck && $colCheck->num_rows > 0) {
                // Reset only previously-set rows to avoid unnecessary writes
                $conn->query("UPDATE mac_device_registry SET match_status = NULL WHERE match_status IS NOT NULL");
                // Batch-update each category
                $allUpdated   = array_values(array_unique($updated_macs));
                $allCurrent   = array_values(array_unique($already_current_macs));
                $allUnmatched = array_values(array_unique($unmatched_macs));
                $statusBatch  = [
                    'updated'         => $allUpdated,
                    'already_current' => $allCurrent,
                    'unmatched'       => $allUnmatched,
                ];
                foreach ($statusBatch as $statusVal => $macList) {
                    foreach ($macList as $m) {
                        $stStmt = $conn->prepare("UPDATE mac_device_registry SET match_status = ? WHERE mac_address = ?");
                        if ($stStmt) {
                            $stStmt->bind_param('ss', $statusVal, $m);
                            $stStmt->execute();
                            $stStmt->close();
                        }
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'updated_count' => $updated_count,
                'message' => "$updated_count port description(s) updated with Device Import data",
                'updated_macs' => array_values(array_unique($updated_macs)),
                'already_current_macs' => array_values(array_unique($already_current_macs)),
                'matched_macs' => array_values(array_unique(array_merge($updated_macs, $already_current_macs))),
                'unmatched_macs' => array_values(array_unique($unmatched_macs))
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        $conn->close();
        exit;
    }
}

// Handle DELETE
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['_method']) && $_GET['_method'] === 'DELETE')) {
    // Use the raw value stored in mac_address (may be a port name, not a
    // standard MAC) – normalizeMac() would return null for those identifiers.
    $mac = isset($_GET['mac']) ? trim($_GET['mac']) : null;
    
    if (!$mac) {
        echo json_encode(['error' => 'MAC address required']);
        exit;
    }
    
    $conn = getDBConnection();
    $stmt = $conn->prepare("DELETE FROM mac_device_registry WHERE mac_address = ?");
    $stmt->bind_param('s', $mac);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Device deleted']);
    } else {
        echo json_encode(['error' => 'Failed to delete device']);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

echo json_encode(['error' => 'Invalid request method']);
?>
