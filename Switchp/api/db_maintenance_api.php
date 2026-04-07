<?php
/**
 * DB Maintenance API (admin-only)
 * Actions: table_sizes, list_foreign_dbs, purge_table, optimize_tables, drop_foreign_tables
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($conn);
$auth->requireLogin();
$currentUser = $auth->getUser();

if ($currentUser['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Yetkisiz erişim']);
    exit;
}

$cfg    = Config::get();
$dbName = $cfg['db_name'];

$method = $_SERVER['REQUEST_METHOD'];
$action = ($method === 'GET') ? ($_GET['action'] ?? '') : (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

// ── GET handlers ──────────────────────────────────────────────────────────────

if ($method === 'GET') {
    if ($action === 'table_sizes') {
        $sql = "SELECT
                    TABLE_NAME                           AS `table`,
                    TABLE_ROWS                           AS `rows`,
                    ROUND((DATA_LENGTH)         / 1048576, 2) AS `data_mb`,
                    ROUND((INDEX_LENGTH)        / 1048576, 2) AS `index_mb`,
                    ROUND((DATA_FREE)           / 1048576, 2) AS `free_mb`,
                    ROUND((DATA_LENGTH + INDEX_LENGTH + DATA_FREE) / 1048576, 2) AS `total_mb`
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = ?
                  AND TABLE_TYPE   = 'BASE TABLE'
                ORDER BY (DATA_LENGTH + INDEX_LENGTH + DATA_FREE) DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $dbName);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'tables' => $rows]);
        exit;
    }

    if ($action === 'list_foreign_dbs') {
        // List databases that are not the current one and not system databases
        $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys'];
        $result = $conn->query("SHOW DATABASES");
        $dbs = [];
        while ($row = $result->fetch_row()) {
            $name = $row[0];
            if ($name === $dbName || in_array($name, $systemDbs)) {
                continue;
            }
            // Get tables and size for each foreign db
            $stmt = $conn->prepare(
                "SELECT TABLE_NAME AS `table`,
                        TABLE_ROWS AS `rows`,
                        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 1) AS `size_kb`
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
                 ORDER BY TABLE_NAME"
            );
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $tables = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $dbs[] = ['db' => $name, 'tables' => $tables];
        }
        echo json_encode(['success' => true, 'databases' => $dbs]);
        exit;
    }

    if ($action === 'list_foreign_keys') {
        // Find duplicate FK constraints: same (table, column, ref_table, ref_column) with more than one constraint name.
        // Use a JOIN instead of a row-constructor IN clause for broader MySQL compatibility.
        $escDb = $conn->real_escape_string($dbName);
        $sql = "SELECT
                    kcu.TABLE_NAME             AS `table`,
                    kcu.COLUMN_NAME            AS `column`,
                    kcu.REFERENCED_TABLE_NAME  AS `ref_table`,
                    kcu.REFERENCED_COLUMN_NAME AS `ref_column`,
                    kcu.CONSTRAINT_NAME        AS `constraint`
                FROM information_schema.KEY_COLUMN_USAGE kcu
                JOIN information_schema.TABLE_CONSTRAINTS tc
                  ON  tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND tc.TABLE_SCHEMA    = kcu.TABLE_SCHEMA
                  AND tc.TABLE_NAME      = kcu.TABLE_NAME
                JOIN (
                    SELECT kcu2.TABLE_NAME AS tn, kcu2.COLUMN_NAME AS cn,
                           kcu2.REFERENCED_TABLE_NAME AS rtn, kcu2.REFERENCED_COLUMN_NAME AS rcn
                    FROM information_schema.KEY_COLUMN_USAGE kcu2
                    JOIN information_schema.TABLE_CONSTRAINTS tc2
                      ON  tc2.CONSTRAINT_NAME = kcu2.CONSTRAINT_NAME
                      AND tc2.TABLE_SCHEMA    = kcu2.TABLE_SCHEMA
                      AND tc2.TABLE_NAME      = kcu2.TABLE_NAME
                    WHERE kcu2.TABLE_SCHEMA    = '{$escDb}'
                      AND tc2.CONSTRAINT_TYPE = 'FOREIGN KEY'
                    GROUP BY kcu2.TABLE_NAME, kcu2.COLUMN_NAME,
                             kcu2.REFERENCED_TABLE_NAME, kcu2.REFERENCED_COLUMN_NAME
                    HAVING COUNT(*) > 1
                ) dup
                  ON  dup.tn  = kcu.TABLE_NAME
                  AND dup.cn  = kcu.COLUMN_NAME
                  AND dup.rtn = kcu.REFERENCED_TABLE_NAME
                  AND dup.rcn = kcu.REFERENCED_COLUMN_NAME
                WHERE kcu.TABLE_SCHEMA    = '{$escDb}'
                  AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
                ORDER BY kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.CONSTRAINT_NAME";

        $result = $conn->query($sql);
        if ($result === false) {
            echo json_encode(['success' => false, 'error' => 'Sorgu hatası: ' . $conn->error]);
            exit;
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'foreign_keys' => $rows]);
        exit;
    }

    if ($action === 'table_data') {
        // Validate table against the actual tables in the current database (admin-only endpoint)
        $table  = $_GET['table'] ?? '';
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit  = min(200, max(1, (int)($_GET['limit'] ?? 50)));

        if ($table === '') {
            echo json_encode(['success' => false, 'error' => 'Tablo adı gerekli']);
            exit;
        }
        // Confirm the table/view actually exists in the current DB (prevents SQL injection via table name)
        $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE IN ('BASE TABLE','VIEW')");
        $chk->bind_param('s', $table);
        $chk->execute();
        $chk->bind_result($exists);
        $chk->fetch();
        $chk->close();
        if (!$exists) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz tablo adı']);
            exit;
        }
        $escTable = $conn->real_escape_string($table);

        $cntRow      = $conn->query("SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$escTable}'")->fetch_row();
        // TABLE_ROWS is NULL for VIEWs; fall back to COUNT(*)
        if (!$cntRow || $cntRow[0] === null) {
            $cntRes = $conn->query("SELECT COUNT(*) FROM `{$escTable}`");
            $approxTotal = $cntRes ? (int)$cntRes->fetch_row()[0] : 0;
        } else {
            $approxTotal = max(0, (int)$cntRow[0]);
        }

        $res = $conn->query("SELECT * FROM `{$escTable}` LIMIT {$limit} OFFSET {$offset}");
        if ($res === false) {
            echo json_encode(['success' => false, 'error' => $conn->error]);
            exit;
        }
        $columns = [];
        foreach ($res->fetch_fields() as $f) $columns[] = $f->name;
        $rows = $res->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['success' => true, 'table' => $table, 'columns' => $columns,
                          'rows' => $rows, 'total' => $approxTotal,
                          'offset' => $offset, 'limit' => $limit]);
        exit;
    }

    if ($action === 'table_list') {
        $res  = $conn->query("SHOW TABLES");
        $list = [];
        while ($r = $res->fetch_row()) $list[] = $r[0];
        echo json_encode(['success' => true, 'tables' => $list]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? '';

    // Purge old rows from any table that has a timestamp/datetime column
    if ($action === 'purge_table') {
        $table   = $data['table'] ?? '';
        $days    = (int)($data['days'] ?? 90);

        if ($table === '') {
            echo json_encode(['success' => false, 'error' => 'Tablo adı gerekli']);
            exit;
        }
        // Validate table exists in current DB (BASE TABLE only — no VIEWs for deletes)
        $chk = $conn->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'");
        $chk->bind_param('s', $table);
        $chk->execute();
        $chk->bind_result($exists);
        $chk->fetch();
        $chk->close();
        if (!$exists) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz tablo adı']);
            exit;
        }
        if ($days < 1 || $days > 3650) {
            echo json_encode(['success' => false, 'error' => 'Geçersiz gün değeri (1–3650)']);
            exit;
        }

        $escTable = $conn->real_escape_string($table);

        // device_polling_data: UPSERT table — one row per device.
        // Delete all rows except the latest (MAX id) per device_id.
        // NOTE: MySQL does not support LIMIT in multi-table DELETE, so we use
        // a subquery approach which deletes all duplicates in a single statement.
        if ($table === 'device_polling_data') {
            $deleted = 0;

            // Step 1: remove duplicates — keep only MAX(id) per device_id
            // Wrap the subquery in another level to avoid MySQL's "can't specify
            // target table for update in FROM clause" restriction.
            $res = $conn->query("
                DELETE FROM `device_polling_data`
                WHERE id NOT IN (
                    SELECT max_id FROM (
                        SELECT MAX(id) AS max_id
                        FROM `device_polling_data`
                        GROUP BY device_id
                    ) AS latest
                )
            ");
            if ($res !== false) {
                $deleted += $conn->affected_rows;
            } else {
                echo json_encode(['success' => false, 'error' => 'Yinelenen satır silme hatası: ' . $conn->error]);
                exit;
            }

            // Step 2: also delete by age in case any stragglers remain
            $stmt = $conn->prepare("DELETE FROM `device_polling_data` WHERE `poll_timestamp` < DATE_SUB(NOW(), INTERVAL ? DAY)");
            if (!$stmt) {
                echo json_encode(['success' => false, 'error' => 'Sorgu hazırlanamadı: ' . $conn->error]);
                exit;
            }
            $stmt->bind_param('i', $days);
            $stmt->execute();
            $deleted += $stmt->affected_rows;

            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'message' => "{$deleted} satır silindi (yinelenenler + {$days} günden eskiler)."
            ]);
            exit;
        }

        // port_change_history: append-only event log — delete by age.
        // Correct column name is change_timestamp.
        $col = 'change_timestamp';
        $colCheck = $conn->query("SHOW COLUMNS FROM `{$escTable}` LIKE '{$col}'");
        if (!$colCheck || $colCheck->num_rows === 0) {
            // Fallback: find any datetime/timestamp column automatically
            $cols = $conn->query("SHOW COLUMNS FROM `{$escTable}` WHERE Type LIKE '%timestamp%' OR Type LIKE '%datetime%'");
            if (!$cols || $cols->num_rows === 0) {
                echo json_encode(['success' => false, 'error' => "'{$escTable}' tablosunda tarih sütunu bulunamadı"]);
                exit;
            }
            $col = $cols->fetch_assoc()['Field'];
        }

        $deleted = 0;
        // Batch-delete in chunks of 10 000 to avoid long table locks
        $stmt = $conn->prepare("DELETE FROM `{$escTable}` WHERE `{$col}` < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 10000");
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Sorgu hazırlanamadı: ' . $conn->error]);
            exit;
        }
        do {
            $stmt->bind_param('i', $days);
            $stmt->execute();
            $deleted += $stmt->affected_rows;
        } while ($stmt->affected_rows > 0);

        echo json_encode([
            'success'  => true,
            'deleted'  => $deleted,
            'message'  => "{$deleted} satır silindi ({$days} günden eski)."
        ]);
        exit;
    }

    // OPTIMIZE TABLE to reclaim free space
    if ($action === 'optimize_tables') {
        $allowed = ['port_change_history', 'device_polling_data'];
        $tables  = $data['tables'] ?? $allowed;
        $tables  = array_filter($tables, fn($t) => in_array($t, $allowed, true));

        if (empty($tables)) {
            echo json_encode(['success' => false, 'error' => 'Geçerli tablo seçilmedi']);
            exit;
        }

        $results = [];
        foreach ($tables as $table) {
            $escTable = $conn->real_escape_string($table);
            $res      = $conn->query("OPTIMIZE TABLE `{$escTable}`");
            $row      = $res ? $res->fetch_assoc() : null;
            $results[$table] = $row['Msg_text'] ?? 'tamamlandı';
        }

        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }

    // Drop tables in a foreign (accidentally created) database
    if ($action === 'drop_foreign_tables') {
        $targetDb = $data['db']     ?? '';
        $tables   = $data['tables'] ?? [];

        if (!$targetDb || empty($tables) || !is_array($tables)) {
            echo json_encode(['success' => false, 'error' => 'Eksik parametre']);
            exit;
        }

        // Never allow touching the main database or system databases
        $forbidden = [$dbName, 'information_schema', 'mysql', 'performance_schema', 'sys'];
        if (in_array($targetDb, $forbidden, true)) {
            echo json_encode(['success' => false, 'error' => 'Bu veritabanına işlem yapılamaz']);
            exit;
        }

        // Verify the database exists
        $stmt = $conn->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->bind_param('s', $targetDb);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Veritabanı bulunamadı']);
            exit;
        }

        // Verify the requested tables actually exist in that database
        $escaped = array_map(fn($t) => "'" . $conn->real_escape_string($t) . "'", $tables);
        $inList  = implode(',', $escaped);
        $escDb   = $conn->real_escape_string($targetDb);
        $existing = $conn->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = '{$escDb}' AND TABLE_NAME IN ({$inList}) AND TABLE_TYPE = 'BASE TABLE'"
        );
        $validTables = [];
        while ($row = $existing->fetch_assoc()) {
            $validTables[] = $row['TABLE_NAME'];
        }

        $dropped = [];
        $errors  = [];
        foreach ($validTables as $table) {
            $escT = $conn->real_escape_string($table);
            if ($conn->query("DROP TABLE `{$escDb}`.`{$escT}`")) {
                $dropped[] = $table;
            } else {
                $errors[] = $table . ': ' . $conn->error;
            }
        }

        // If all tables in that DB are now gone, offer to drop the empty DB
        $remaining = $conn->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$escDb}'"
        );
        $remainCount = (int)$remaining->fetch_assoc()['cnt'];

        echo json_encode([
            'success'        => empty($errors),
            'dropped'        => $dropped,
            'errors'         => $errors,
            'db_empty'       => ($remainCount === 0),
            'remaining_tables' => $remainCount
        ]);
        exit;
    }

    // Drop an entire foreign database (only when it is confirmed empty by the client)
    if ($action === 'drop_foreign_db') {
        $targetDb = $data['db'] ?? '';

        $forbidden = [$dbName, 'information_schema', 'mysql', 'performance_schema', 'sys'];
        if (!$targetDb || in_array($targetDb, $forbidden, true)) {
            echo json_encode(['success' => false, 'error' => 'Bu veritabanı silinemez']);
            exit;
        }

        // Safety: confirm the DB has no remaining tables
        $escDb = $conn->real_escape_string($targetDb);
        $check = $conn->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$escDb}'"
        );
        if (!$check || (int)$check->fetch_assoc()['cnt'] > 0) {
            echo json_encode(['success' => false, 'error' => 'Veritabanı hâlâ tablo içeriyor; önce tabloları silin']);
            exit;
        }

        if ($conn->query("DROP DATABASE `{$escDb}`")) {
            echo json_encode(['success' => true, 'message' => "'{$targetDb}' veritabanı silindi."]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        exit;
    }

    // Drop a table from the main database (orphan / unwanted tables)
    if ($action === 'drop_main_table') {
        $table = $data['table'] ?? '';

        if (!$table) {
            echo json_encode(['success' => false, 'error' => 'Tablo adı gerekli']);
            exit;
        }

        // Verify the table actually exists in the main database
        $stmt = $conn->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->bind_param('ss', $dbName, $table);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Tablo bulunamadı']);
            exit;
        }

        $escTable = $conn->real_escape_string($table);
        // Disable FK checks temporarily so dependent constraints don't block the drop
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
        $ok = $conn->query("DROP TABLE `{$escTable}`");
        $conn->query("SET FOREIGN_KEY_CHECKS=1");

        if ($ok) {
            echo json_encode(['success' => true, 'message' => "'{$table}' tablosu silindi."]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        exit;
    }

    // Drop a single duplicate FK constraint
    if ($action === 'drop_foreign_key') {
        $table      = $data['table']      ?? '';
        $constraint = $data['constraint'] ?? '';

        if (!$table || !$constraint) {
            echo json_encode(['success' => false, 'error' => 'Eksik parametre']);
            exit;
        }

        // Verify the constraint exists in the main database
        $stmt = $conn->prepare(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->bind_param('sss', $dbName, $table, $constraint);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Kısıtlama bulunamadı']);
            exit;
        }

        $escTable      = $conn->real_escape_string($table);
        $escConstraint = $conn->real_escape_string($constraint);
        if ($conn->query("ALTER TABLE `{$escTable}` DROP FOREIGN KEY `{$escConstraint}`")) {
            echo json_encode(['success' => true, 'message' => "'{$constraint}' kısıtlaması silindi."]);
        } else {
            echo json_encode(['success' => false, 'error' => $conn->error]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Geçersiz işlem']);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Desteklenmeyen HTTP metodu']);
