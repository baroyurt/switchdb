<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($conn);
$auth->requireLogin();
$currentUser = $auth->getUser();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ─── Tablo satır sayıları ve disk boyutları ───────────────────────────────
$tableStats = [];
$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];
$tsResult = $conn->query("
    SELECT TABLE_NAME,
           TABLE_ROWS,
           ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
           DATA_LENGTH + INDEX_LENGTH AS size_bytes
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($dbName) . "'
    ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
");
while ($row = $tsResult->fetch_assoc()) {
    $tableStats[] = $row;
}

// ─── device_polling_data — cihaz başına istatistik (anlık değerler) ─────────
// cpu_1min ve temperature_c snmp_devices'den gelir (her poll'da UPDATE edilir).
// memory_usage için en son device_polling_data satırına bakılır.
$pollingStats = [];
$psResult = $conn->query("
    SELECT d.id, d.name, d.ip_address, d.status, d.vendor, d.model,
           d.last_poll_time,
           d.last_successful_poll,
           d.poll_failures,
           ROUND(d.cpu_1min, 1)      AS current_cpu,
           (SELECT ROUND(pp.memory_usage, 1)
            FROM device_polling_data pp
            WHERE pp.device_id = d.id
            ORDER BY pp.poll_timestamp DESC
            LIMIT 1)                 AS current_mem,
           (SELECT COUNT(*)
            FROM device_polling_data pp2
            WHERE pp2.device_id = d.id) AS row_count,
           (SELECT MIN(pp3.poll_timestamp)
            FROM device_polling_data pp3
            WHERE pp3.device_id = d.id) AS oldest,
           (SELECT MAX(pp4.poll_timestamp)
            FROM device_polling_data pp4
            WHERE pp4.device_id = d.id) AS newest
    FROM snmp_devices d
    ORDER BY d.name
");
while ($row = $psResult->fetch_assoc()) {
    $pollingStats[] = $row;
}

// ─── port_status_data — cihaz başına güncel port sayıları ─────────────────
$portStats = [];
$prResult = $conn->query("
    SELECT d.name, d.ip_address,
           COUNT(ps.id)                          AS total_ports,
           SUM(ps.oper_status = 'up')            AS ports_up,
           SUM(ps.oper_status = 'down')          AS ports_down,
           MAX(ps.poll_timestamp)                AS last_poll
    FROM snmp_devices d
    LEFT JOIN port_status_data ps ON ps.device_id = d.id
    GROUP BY d.id, d.name, d.ip_address
    ORDER BY total_ports DESC
");
while ($row = $prResult->fetch_assoc()) {
    $portStats[] = $row;
}

// ─── mac_tracking_history — son 50 değişim + toplam sayı ─────────────────
$macTotal = 0;
$macOldest = null;
$macHistoryRows = [];
$mtCheck = $conn->query("SHOW TABLES LIKE 'mac_tracking_history'");
if ($mtCheck && $mtCheck->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) AS c, MIN(detected_at) AS oldest FROM mac_tracking_history");
    if ($r) {
        $row = $r->fetch_assoc();
        $macTotal   = (int)$row['c'];
        $macOldest  = $row['oldest'];
    }
    $mr = $conn->query("
        SELECT m.change_type, m.port_number, m.old_mac, m.new_mac, m.detected_at,
               d.name AS device_name
        FROM mac_tracking_history m
        LEFT JOIN snmp_devices d ON d.id = m.device_id
        ORDER BY m.detected_at DESC
        LIMIT 50
    ");
    if ($mr) {
        while ($row = $mr->fetch_assoc()) {
            $macHistoryRows[] = $row;
        }
    }
}

// ─── alarms — durum + tür başına dağılım ──────────────────────────────────
$alarmStats = [];
$arResult = $conn->query("
    SELECT alarm_type,
           severity,
           status,
           COUNT(*) AS cnt,
           MAX(last_occurrence) AS last_seen
    FROM alarms
    GROUP BY alarm_type, severity, status
    ORDER BY cnt DESC
");
while ($row = $arResult->fetch_assoc()) {
    $alarmStats[] = $row;
}

// ─── Toplam polling satır sayısı ─────────────────────────────────────────
$totalPollingRows = array_sum(array_column($pollingStats, 'row_count'));
$oldestPolling = null;
foreach ($pollingStats as $s) {
    if ($s['oldest'] && (!$oldestPolling || $s['oldest'] < $oldestPolling)) {
        $oldestPolling = $s['oldest'];
    }
}

// ─── port_snapshot tablosu ───────────────────────────────────────────────
$snapshotTotal = 0;
$snapshotOldest = null;
$snapCheck = $conn->query("SHOW TABLES LIKE 'port_snapshot'");
if ($snapCheck && $snapCheck->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) AS c, MIN(snapshot_timestamp) AS oldest FROM port_snapshot");
    if ($r) {
        $row = $r->fetch_assoc();
        $snapshotTotal  = (int)$row['c'];
        $snapshotOldest = $row['oldest'];
    }
}

// ─── Büyüyen tablolar için sütun + satır listesi ──────────────────────────
// port_change_history: son 1000 satır gösterilir, toplam sayı ayrıca alınır
// device_polling_data: UPSERT — 38 satır, tümü gösterilir
$tableInspect = [];
// Per-table row fetch limits: device_polling_data is 38 rows — no limit needed;
// port_change_history can be huge — cap at 1000 for browser safety.
$tblLimit = ['port_change_history' => 1000, 'device_polling_data' => 9999];
foreach (['port_change_history', 'device_polling_data'] as $tbl) {
    $check = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tbl) . "'");
    if (!$check || $check->num_rows === 0) continue;

    $columns = [];
    $cr = $conn->query("DESCRIBE `" . $conn->real_escape_string($tbl) . "`");
    if ($cr) {
        while ($c = $cr->fetch_assoc()) $columns[] = $c;
    }

    // Fetch total row count first (cheap COUNT query)
    $totalRows = 0;
    $tr = $conn->query("SELECT COUNT(*) AS c FROM `" . $conn->real_escape_string($tbl) . "`");
    if ($tr) $totalRows = (int)$tr->fetch_assoc()['c'];

    $limit = $tblLimit[$tbl] ?? 500;
    $samples = [];
    $sr = $conn->query("SELECT * FROM `" . $conn->real_escape_string($tbl) . "` ORDER BY 1 DESC LIMIT " . $limit);
    if ($sr) {
        while ($s = $sr->fetch_assoc()) $samples[] = $s;
    }

    $tableInspect[$tbl] = ['columns' => $columns, 'samples' => $samples, 'total' => $totalRows];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Veri Görünümü — Switchp</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
html { zoom: 0.9; }
:root {
    --primary: #3b82f6;
    --primary-dark: #2563eb;
    --success: #10b981;
    --warning: #f59e0b;
    --danger: #ef4444;
    --info: #06b6d4;
    --dark: #0f172a;
    --dark-light: #1e293b;
    --dark-mid: #162032;
    --text: #e2e8f0;
    --text-light: #94a3b8;
    --border: #334155;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: var(--dark);
    color: var(--text);
    padding: 20px;
}
h1 { font-size: 22px; color: var(--text); margin-bottom: 4px; }
.subtitle { font-size: 13px; color: var(--text-light); margin-bottom: 24px; }

/* ── Tabs ── */
.tabs { display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap; }
.tab-btn {
    padding: 8px 18px; border: 1px solid var(--border); border-radius: 8px;
    background: var(--dark-light); color: var(--text-light); cursor: pointer;
    font-size: 13px; font-weight: 600; transition: all .2s;
}
.tab-btn:hover { border-color: var(--primary); color: var(--primary); }
.tab-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── Section card ── */
.card {
    background: var(--dark-light); border: 1px solid var(--border);
    border-radius: 12px; padding: 20px; margin-bottom: 20px;
}
.card-title {
    font-size: 15px; font-weight: 700; color: var(--text);
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.card-title i { color: var(--primary); }

/* ── Summary badges ── */
.badge-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; }
.badge {
    background: var(--dark-mid); border: 1px solid var(--border);
    border-radius: 10px; padding: 12px 18px; min-width: 130px; text-align: center;
}
.badge .val { font-size: 26px; font-weight: 800; color: var(--primary); }
.badge .lbl { font-size: 11px; color: var(--text-light); margin-top: 2px; }
.badge.warn .val { color: var(--warning); }
.badge.danger .val { color: var(--danger); }
.badge.success .val { color: var(--success); }
.badge.info-c .val { color: var(--info); }

/* ── Tables ── */
.data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.data-table th {
    background: var(--dark); color: var(--text-light); text-align: left;
    padding: 10px 12px; border-bottom: 1px solid var(--border);
    font-weight: 600; position: sticky; top: 0; z-index: 1;
}
.data-table td { padding: 9px 12px; border-bottom: 1px solid rgba(51,65,85,.4); vertical-align: middle; }
.data-table tr:hover td { background: rgba(59,130,246,.06); }
.data-table tr:last-child td { border-bottom: none; }
.tbl-wrap { max-height: 420px; overflow-y: auto; border-radius: 8px; border: 1px solid var(--border); }

/* ── Status pills ── */
.pill {
    display: inline-block; padding: 2px 9px; border-radius: 20px;
    font-size: 11px; font-weight: 700; text-transform: uppercase;
}
.pill-up      { background: rgba(16,185,129,.15); color: #10b981; }
.pill-down    { background: rgba(239,68,68,.15);  color: #ef4444; }
.pill-online  { background: rgba(16,185,129,.15); color: #10b981; }
.pill-offline { background: rgba(239,68,68,.15);  color: #ef4444; }
.pill-error   { background: rgba(245,158,11,.15); color: #f59e0b; }
.pill-unknown { background: rgba(148,163,184,.15);color: #94a3b8; }
.pill-active  { background: rgba(239,68,68,.15);  color: #ef4444; }
.pill-resolved{ background: rgba(16,185,129,.15); color: #10b981; }
.pill-acknowledged { background: rgba(245,158,11,.15); color: #f59e0b; }

/* ── Bar chart cell ── */
.bar-cell { display: flex; align-items: center; gap: 8px; }
.bar-track {
    flex: 1; height: 8px; background: rgba(51,65,85,.6);
    border-radius: 4px; overflow: hidden; min-width: 80px;
}
.bar-fill { height: 100%; border-radius: 4px; background: var(--primary); }
.bar-fill.warn  { background: var(--warning); }
.bar-fill.danger{ background: var(--danger); }
.bar-fill.success{background: var(--success); }

/* ── SNMP collection table ── */
.snmp-chip {
    display: inline-block; margin: 2px 3px; padding: 3px 9px;
    border-radius: 6px; font-size: 11px; font-weight: 600;
    background: rgba(59,130,246,.15); color: #93c5fd; border: 1px solid rgba(59,130,246,.25);
}
.snmp-chip.heavy { background: rgba(239,68,68,.12); color: #fca5a5; border-color: rgba(239,68,68,.25); }
.snmp-chip.light { background: rgba(16,185,129,.12); color: #6ee7b7; border-color: rgba(16,185,129,.25); }

/* ── Grow indicator ── */
.grow-tag {
    font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px;
    text-transform: uppercase; letter-spacing: .5px;
}
.grow-tag.grows   { background: rgba(239,68,68,.15); color: #f87171; }
.grow-tag.fixed   { background: rgba(16,185,129,.15); color: #34d399; }
.grow-tag.event   { background: rgba(245,158,11,.15); color: #fbbf24; }

/* ── Misc ── */
.mono { font-family: 'Courier New', monospace; font-size: 12px; }
.dim  { color: var(--text-light); font-size: 12px; }
.text-right { text-align: right; }
a.back-btn {
    display: inline-flex; align-items: center; gap: 6px;
    margin-bottom: 16px; color: var(--text-light); text-decoration: none;
    font-size: 13px; transition: color .2s;
}
a.back-btn:hover { color: var(--primary); }
</style>
</head>
<body>

<a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Ana Sayfa</a>

<h1><i class="fas fa-database" style="color:var(--primary)"></i> Veri Görünümü</h1>
<p class="subtitle">
    Veritabanında ne kadar veri var, hangi tablolar büyüyor, SNMP ne topluyor — hepsini buradan görün.
</p>

<!-- ── Summary badges ── -->
<div class="badge-row">
    <div class="badge">
        <div class="val"><?php echo count($pollingStats); ?></div>
        <div class="lbl">Aktif Switch</div>
    </div>
    <div class="badge <?php echo $totalPollingRows > 50000 ? 'danger' : ($totalPollingRows > 10000 ? 'warn' : ''); ?>">
        <div class="val"><?php echo number_format($totalPollingRows); ?></div>
        <div class="lbl">device_polling_data satır</div>
    </div>
    <div class="badge info-c">
        <div class="val"><?php echo number_format(array_sum(array_column($portStats, 'total_ports'))); ?></div>
        <div class="lbl">port_status_data satır (sabit)</div>
    </div>
    <div class="badge <?php echo $macTotal > 50000 ? 'danger' : ($macTotal > 5000 ? 'warn' : ''); ?>">
        <div class="val"><?php echo number_format($macTotal); ?></div>
        <div class="lbl">mac_tracking_history satır</div>
    </div>
    <div class="badge">
        <div class="val"><?php echo number_format($snapshotTotal); ?></div>
        <div class="lbl">port_snapshot satır</div>
    </div>
    <?php
    $totalAlarms = array_sum(array_column($alarmStats, 'cnt'));
    $activeAlarms = 0;
    foreach ($alarmStats as $a) {
        if (strtoupper($a['status']) === 'ACTIVE') $activeAlarms += (int)$a['cnt'];
    }
    ?>
    <div class="badge <?php echo $activeAlarms > 0 ? 'danger' : 'success'; ?>">
        <div class="val"><?php echo number_format($activeAlarms); ?></div>
        <div class="lbl">Aktif Alarm</div>
    </div>
</div>

<!-- ── Tabs ── -->
<div class="tabs">
    <button class="tab-btn active" onclick="showTab('db-sizes')"><i class="fas fa-hdd"></i> DB Boyutları</button>
    <button class="tab-btn" onclick="showTab('polling')"><i class="fas fa-chart-line"></i> Polling Verisi</button>
    <button class="tab-btn" onclick="showTab('ports')"><i class="fas fa-plug"></i> Port Durumu</button>
    <button class="tab-btn" onclick="showTab('mac')"><i class="fas fa-exchange-alt"></i> MAC Değişimleri</button>
    <button class="tab-btn" onclick="showTab('alarms')"><i class="fas fa-bell"></i> Alarmlar</button>
    <button class="tab-btn" onclick="showTab('snmp-collect')"><i class="fas fa-broadcast-tower"></i> SNMP Toplama</button>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 1 — DB Boyutları                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-db-sizes" class="tab-panel active">
<div class="card">
    <div class="card-title"><i class="fas fa-hdd"></i> Veritabanı Tablo Boyutları</div>
    <?php
    $totalMB = array_sum(array_column($tableStats, 'size_mb'));
    $maxMB   = max(array_column($tableStats, 'size_mb') ?: [1]);
    ?>
    <div style="margin-bottom:12px; color:var(--text-light); font-size:13px;">
        Toplam boyut: <strong style="color:var(--text)"><?php echo number_format($totalMB, 2); ?> MB</strong>
        &nbsp;|&nbsp; Veritabanı: <strong style="color:var(--text)"><?php echo htmlspecialchars($dbName); ?></strong>
    </div>
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Tablo Adı</th>
                <th>Büyüme Tipi</th>
                <th>Satır Sayısı (tahmini)</th>
                <th>Boyut (MB)</th>
                <th>Boyut Grafiği</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $growthMap = [
            'port_change_history'    => ['grows',  'Her port değişiminde yeni satır INSERT'],
            'alarms'                 => ['event',  'Olay günlüğü — yeni alarm = yeni satır'],
            'mac_tracking_history'   => ['event',  'Olay günlüğü — her MAC değişimi = yeni satır'],
            'port_snapshot'          => ['event',  'Periyodik anlık görüntü'],
            'connection_history'     => ['event',  'Manuel bağlantı değişimleri'],
            'activity_log'           => ['event',  'Kullanıcı işlem günlüğü'],
            'device_polling_data'    => ['fixed',  'UPSERT — cihaz başına 1 satır (sabit)'],
            'port_status_data'       => ['fixed',  'UPSERT — cihaz başına 1 satır (sabit)'],
            'snmp_devices'           => ['fixed',  'Switch listesi — sabit'],
            'switches'               => ['fixed',  'Switch listesi — sabit'],
            'ports'                  => ['fixed',  'Port listesi — sabit'],
            'mac_device_registry'    => ['fixed',  'MAC-cihaz eşlemesi — Manuel ekleme'],
            'acknowledged_port_mac'  => ['fixed',  'Beyaz liste — Manuel ekleme'],
        ];
        foreach ($tableStats as $t):
            $tname = $t['TABLE_NAME'];
            $gt = $growthMap[$tname] ?? ['unknown',''];
            $pct = $maxMB > 0 ? min(100, round($t['size_mb'] / $maxMB * 100)) : 0;
            $barClass = $t['size_mb'] > 50 ? 'danger' : ($t['size_mb'] > 10 ? 'warn' : 'success');
            $pillClass = ['grows'=>'danger','event'=>'warn','fixed'=>'fixed','unknown'=>''][$gt[0]] ?? '';
        ?>
        <tr>
            <td><span class="mono"><?php echo htmlspecialchars($tname); ?></span></td>
            <td>
                <span class="grow-tag grows" style="<?php echo $gt[0]==='grows'?'':'display:none'; ?>">Büyüyor</span>
                <span class="grow-tag event" style="<?php echo $gt[0]==='event'?'':'display:none'; ?>">Olay Günlüğü</span>
                <span class="grow-tag fixed" style="<?php echo $gt[0]==='fixed'?'':'display:none'; ?>">Sabit</span>
                <?php if ($gt[1]): ?><br><span class="dim"><?php echo htmlspecialchars($gt[1]); ?></span><?php endif; ?>
            </td>
            <td class="text-right"><?php echo number_format((int)$t['TABLE_ROWS']); ?></td>
            <td class="text-right"><?php echo number_format($t['size_mb'], 3); ?> MB</td>
            <td>
                <div class="bar-cell">
                    <div class="bar-track"><div class="bar-fill <?php echo $barClass; ?>" style="width:<?php echo $pct; ?>%"></div></div>
                    <span class="dim" style="min-width:42px"><?php echo $pct; ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="dim" style="margin-top:10px"><i class="fas fa-info-circle"></i>
        <strong style="color:var(--warning)">Büyüyor</strong> etiketli tablolar temizlik gerektiriyor.
        <strong style="color:var(--success)">Sabit</strong> tablolar UPSERT ile güncelleniyor, büyümüyor.
        <strong style="color:var(--warning)">Olay Günlüğü</strong> tabloları gerektiğinde temizlenebilir.
    </p>
</div>

<!-- ─── Büyüyen tabloların içeriği (sütunlar + örnek satırlar) ─────────── -->
<?php
$inspectLabels = [
    'port_change_history' => ['🔴 port_change_history', 'Her port değişiminde yeni satır ekler — TÜM satırlar gösteriliyor (detaylı analiz)'],
    'device_polling_data' => ['🟢 device_polling_data', 'UPSERT — cihaz başına 1 satır, her poll güncellenir (sabit boyut)'],
];
$inspectBorders = [
    'port_change_history' => 'rgba(239,68,68,.3)',
    'device_polling_data' => 'rgba(16,185,129,.3)',
];
$inspectIconColors = [
    'port_change_history' => '#f87171',
    'device_polling_data' => '#34d399',
];
foreach ($tableInspect as $tbl => $info):
    [$title, $desc] = $inspectLabels[$tbl] ?? [$tbl, ''];
    $borderColor   = $inspectBorders[$tbl]    ?? 'rgba(239,68,68,.3)';
    $iconColor     = $inspectIconColors[$tbl] ?? '#f87171';
?>
<div class="card" style="border-color:<?php echo $borderColor; ?>">
    <div class="card-title" style="font-size:16px">
        <i class="fas fa-table" style="color:<?php echo $iconColor; ?>"></i>
        <?php echo htmlspecialchars($title); ?>
        <span class="dim" style="font-size:12px;font-weight:400;margin-left:8px"><?php echo number_format($info['total']); ?> satır</span>
    </div>
    <p class="dim" style="margin-bottom:14px;font-size:13px"><?php echo htmlspecialchars($desc); ?></p>

    <!-- Sütun Yapısı -->
    <div style="font-size:13px;font-weight:700;color:var(--text-light);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">
        <i class="fas fa-columns"></i> Sütunlar (DESCRIBE)
    </div>
    <div class="tbl-wrap" style="margin-bottom:20px">
    <table class="data-table" style="font-size:12px">
        <thead>
            <tr>
                <th>Alan Adı</th>
                <th>Tip</th>
                <th>Null</th>
                <th>Key</th>
                <th>Varsayılan</th>
                <th>Extra</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($info['columns'] as $col): ?>
        <tr>
            <td><span class="mono" style="color:#93c5fd"><?php echo htmlspecialchars($col['Field']); ?></span></td>
            <td><span class="mono" style="color:#fbbf24"><?php echo htmlspecialchars($col['Type']); ?></span></td>
            <td style="color:<?php echo $col['Null']==='YES'?'#94a3b8':'#34d399'; ?>"><?php echo htmlspecialchars($col['Null']); ?></td>
            <td><?php echo $col['Key'] ? '<span style="color:#f87171;font-weight:700">'.htmlspecialchars($col['Key']).'</span>' : '<span class="dim">—</span>'; ?></td>
            <td class="dim mono"><?php echo $col['Default'] !== null ? htmlspecialchars($col['Default']) : '<em>NULL</em>'; ?></td>
            <td class="dim"><?php echo $col['Extra'] ? htmlspecialchars($col['Extra']) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Satır Listesi -->
    <?php
    $shownCount = count($info['samples']);
    $totalCount = $info['total'];
    $isCapped    = $shownCount < $totalCount;
    ?>
    <?php if (!empty($info['samples'])): ?>
    <div style="font-size:13px;font-weight:700;color:var(--text-light);margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em">
        <i class="fas fa-eye"></i>
        <?php if ($isCapped): ?>
            Son <?php echo number_format($shownCount); ?> Satır
            <span class="dim" style="font-weight:400;font-size:11px;text-transform:none">(toplam <?php echo number_format($totalCount); ?> kayıt — en yeni <?php echo number_format($shownCount); ?> tanesi gösteriliyor)</span>
        <?php else: ?>
            Tüm Satırlar — <?php echo number_format($totalCount); ?> kayıt
        <?php endif; ?>
    </div>
    <div class="tbl-wrap">
    <table class="data-table" style="font-size:11px">
        <thead>
            <tr>
            <?php foreach (array_keys($info['samples'][0]) as $col): ?>
                <th><?php echo htmlspecialchars($col); ?></th>
            <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($info['samples'] as $srow): ?>
        <tr>
            <?php foreach ($srow as $v): ?>
            <td class="mono" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo htmlspecialchars((string)$v); ?>">
                <?php echo $v !== null ? htmlspecialchars((string)$v) : '<span class="dim">NULL</span>'; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <p class="dim"><i class="fas fa-inbox"></i> Tablo boş — henüz satır yok.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 2 — Polling Verisi                                                -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-polling" class="tab-panel">
<div class="card">
    <div class="card-title"><i class="fas fa-microchip"></i> Anlık Cihaz Durumu — <?php echo count($pollingStats); ?> Switch, <?php echo number_format($totalPollingRows); ?> Satır</div>
    <p class="dim" style="margin-bottom:14px">
        CPU% ve Bellek%, <code>snmp_devices</code> tablosundaki anlık (canlı) değerlerdir — her poll'da güncellenir.
        &nbsp;<strong style="color:var(--success)">device_polling_data</strong> artık UPSERT modundadır —
        her cihaz için tek bir satır tutulur, her poll'da güncellenir (sabit boyut).
    </p>
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Cihaz</th>
                <th>IP</th>
                <th>Durum</th>
                <th>Anlık CPU %</th>
                <th>Anlık Bellek %</th>
                <th>Son Poll</th>
                <th>Son Başarılı Poll</th>
                <th class="text-right">Hata Sayısı</th>
                <th class="text-right">DB Satır Sayısı</th>
            </tr>
        </thead>
        <tbody>
        <?php
        foreach ($pollingStats as $p):
            $statusLC = strtolower($p['status'] ?? '');
            $pillC    = $statusLC === 'online' ? 'online' : ($statusLC === 'offline' ? 'offline' : 'error');
            $cpu  = $p['current_cpu'];
            $mem  = $p['current_mem'];
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($p['name']); ?></strong>
                <br><span class="dim"><?php echo htmlspecialchars(trim($p['vendor'] . ' ' . $p['model'])); ?></span>
            </td>
            <td class="mono"><?php echo htmlspecialchars($p['ip_address']); ?></td>
            <td><span class="pill pill-<?php echo $pillC; ?>"><?php echo htmlspecialchars(strtoupper($p['status'] ?? '—')); ?></span></td>
            <td>
                <?php if ($cpu !== null): ?>
                <div class="bar-cell">
                    <div class="bar-track"><div class="bar-fill <?php echo $cpu>80?'danger':($cpu>50?'warn':'success'); ?>" style="width:<?php echo min(100,(int)$cpu); ?>%"></div></div>
                    <span style="font-weight:700;color:<?php echo $cpu>80?'#f87171':($cpu>50?'#fbbf24':'#34d399'); ?>"><?php echo $cpu; ?>%</span>
                </div>
                <?php else: ?><span class="dim">—</span><?php endif; ?>
            </td>
            <td>
                <?php if ($mem !== null): ?>
                <div class="bar-cell">
                    <div class="bar-track"><div class="bar-fill <?php echo $mem>90?'danger':($mem>70?'warn':'success'); ?>" style="width:<?php echo min(100,(int)$mem); ?>%"></div></div>
                    <span style="font-weight:700;color:<?php echo $mem>90?'#f87171':($mem>70?'#fbbf24':'#34d399'); ?>"><?php echo $mem; ?>%</span>
                </div>
                <?php else: ?><span class="dim">—</span><?php endif; ?>
            </td>
            <td class="dim"><?php echo $p['last_poll_time'] ? date('d.m.Y H:i', strtotime($p['last_poll_time'])) : '—'; ?></td>
            <td class="dim"><?php echo $p['last_successful_poll'] ? date('d.m.Y H:i', strtotime($p['last_successful_poll'])) : '—'; ?></td>
            <td class="text-right">
                <?php $fail = (int)$p['poll_failures']; ?>
                <span style="color:<?php echo $fail>10?'#f87171':($fail>0?'#fbbf24':'#34d399'); ?>;font-weight:700"><?php echo $fail; ?></span>
            </td>
            <td class="text-right">
                <?php if ($p['row_count'] > 0): ?>
                <span style="font-weight:700;color:<?php echo $p['row_count']>5000?'#f87171':($p['row_count']>1000?'#fbbf24':'var(--text)'); ?>">
                    <?php echo number_format((int)$p['row_count']); ?>
                </span>
                <?php else: ?><span class="dim">0</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div style="margin-top:14px; padding:12px; background:rgba(239,68,68,.07); border:1px solid rgba(239,68,68,.2); border-radius:8px;">
        <strong style="color:#f87171"><i class="fas fa-exclamation-circle"></i> Neden büyüyor?</strong>
        <span class="dim" style="font-size:13px; margin-left:6px">
            Her poll döngüsünde yeni satır INSERT edilir. MySQL <code>event_scheduler</code> kapalıysa 7 günlük temizlik çalışmaz.
            Temizlemek için: <code style="color:#93c5fd">SET GLOBAL event_scheduler = ON;</code> veya
            <code style="color:#93c5fd">DELETE FROM device_polling_data WHERE poll_timestamp &lt; DATE_SUB(NOW(), INTERVAL 7 DAY);</code>
        </span>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 3 — Port Durumu                                                   -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-ports" class="tab-panel">
<div class="card">
    <div class="card-title"><i class="fas fa-plug"></i> port_status_data — Güncel Port Durumları (UPSERT — Sabit Boyut)</div>
    <p class="dim" style="margin-bottom:14px">
        Bu tablo <strong style="color:var(--success)">büyümez</strong> — her cihaz/port çifti için tek satır vardır.
        Python worker her poll döngüsünde mevcut satırı günceller (UPSERT).
    </p>
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Cihaz</th>
                <th>IP</th>
                <th class="text-right">Toplam Port</th>
                <th class="text-right">UP</th>
                <th class="text-right">DOWN</th>
                <th>UP Oranı</th>
                <th>Son Poll</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($portStats as $p):
            $up   = (int)$p['ports_up'];
            $down = (int)$p['ports_down'];
            $tot  = (int)$p['total_ports'];
            $pct  = $tot > 0 ? round($up / $tot * 100) : 0;
        ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($p['name']); ?></strong></td>
            <td class="mono"><?php echo htmlspecialchars($p['ip_address']); ?></td>
            <td class="text-right"><?php echo $tot; ?></td>
            <td class="text-right" style="color:#34d399;font-weight:700"><?php echo $up; ?></td>
            <td class="text-right" style="color:#f87171;font-weight:700"><?php echo $down; ?></td>
            <td>
                <div class="bar-cell">
                    <div class="bar-track"><div class="bar-fill success" style="width:<?php echo $pct; ?>%"></div></div>
                    <span class="dim"><?php echo $pct; ?>%</span>
                </div>
            </td>
            <td class="dim"><?php echo $p['last_poll'] ? date('d.m.Y H:i', strtotime($p['last_poll'])) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 4 — MAC Değişimleri                                               -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-mac" class="tab-panel">
<div class="card">
    <div class="card-title"><i class="fas fa-exchange-alt"></i> mac_tracking_history</div>
    <div class="badge-row" style="margin-bottom:14px">
        <div class="badge <?php echo $macTotal>50000?'danger':($macTotal>5000?'warn':''); ?>">
            <div class="val"><?php echo number_format($macTotal); ?></div>
            <div class="lbl">Toplam Satır</div>
        </div>
        <?php if ($macOldest): ?>
        <div class="badge">
            <div class="val" style="font-size:16px"><?php echo date('d.m.Y', strtotime($macOldest)); ?></div>
            <div class="lbl">En Eski Kayıt</div>
        </div>
        <?php endif; ?>
    </div>
    <?php if (empty($macHistoryRows)): ?>
        <p class="dim"><i class="fas fa-check-circle" style="color:var(--success)"></i> Henüz MAC değişim kaydı yok.</p>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Zaman</th>
                <th>Cihaz</th>
                <th>Port</th>
                <th>Değişim Tipi</th>
                <th>Eski MAC</th>
                <th>Yeni MAC</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($macHistoryRows as $m): ?>
        <tr>
            <td class="dim"><?php echo date('d.m.Y H:i:s', strtotime($m['detected_at'])); ?></td>
            <td><?php echo htmlspecialchars($m['device_name'] ?? '—'); ?></td>
            <td class="text-right"><?php echo htmlspecialchars($m['port_number'] ?? '—'); ?></td>
            <td><span class="pill pill-<?php echo strtolower($m['change_type'] ?? '') === 'added' ? 'up' : 'down'; ?>">
                <?php echo htmlspecialchars(strtoupper($m['change_type'] ?? '—')); ?>
            </span></td>
            <td class="mono dim"><?php echo htmlspecialchars($m['old_mac'] ?? '—'); ?></td>
            <td class="mono"><?php echo htmlspecialchars($m['new_mac'] ?? '—'); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="dim" style="margin-top:10px">Son 50 kayıt gösteriliyor. Toplam: <?php echo number_format($macTotal); ?> satır.</p>
    <?php endif; ?>
</div>

<?php if ($snapshotTotal > 0): ?>
<div class="card">
    <div class="card-title"><i class="fas fa-camera"></i> port_snapshot</div>
    <p class="dim">Toplam <strong style="color:var(--text)"><?php echo number_format($snapshotTotal); ?></strong> anlık görüntü kaydı.
    <?php echo $snapshotOldest ? 'En eski: <strong style="color:var(--text)">'.date('d.m.Y', strtotime($snapshotOldest)).'</strong>' : ''; ?>
    </p>
</div>
<?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 5 — Alarmlar                                                      -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-alarms" class="tab-panel">
<div class="card">
    <div class="card-title"><i class="fas fa-bell"></i> Alarm Dağılımı</div>
    <?php if (empty($alarmStats)): ?>
        <p class="dim"><i class="fas fa-check-circle" style="color:var(--success)"></i> Alarm kaydı yok.</p>
    <?php else: ?>
    <div class="tbl-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>Alarm Tipi</th>
                <th>Önem</th>
                <th>Durum</th>
                <th class="text-right">Sayı</th>
                <th>Son Görülme</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $alarmTypeNames = [
            'port_down'           => 'Port DOWN',
            'port_up'             => 'Port UP (Kurtarma)',
            'device_unreachable'  => 'Cihaz Erişilemiyor',
            'device_recovered'    => 'Cihaz Kurtarıldı',
            'snmp_error'          => 'SNMP Hatası',
            'mac_change'          => 'MAC Değişimi',
            'mac_moved'           => 'MAC Taşındı',
            'mac_added'           => 'MAC Eklendi',
            'mac_removed'         => 'MAC Silindi',
            'vlan_change'         => 'VLAN Değişimi',
            'vlan_changed'        => 'VLAN Değişimi',
            'description_changed' => 'Açıklama Değişimi',
            'core_link_down'      => 'Çekirdek Bağlantı Koptu',
        ];
        $sevColors = [
            'critical'=>'#f87171','high'=>'#fb923c','medium'=>'#fbbf24',
            'low'=>'#a3e635','info'=>'#34d399',
            'CRITICAL'=>'#f87171','HIGH'=>'#fb923c','MEDIUM'=>'#fbbf24',
            'LOW'=>'#a3e635','INFO'=>'#34d399',
        ];
        foreach ($alarmStats as $a):
            $sev  = $a['severity'] ?? 'unknown';
            $sevC = $sevColors[$sev] ?? '#94a3b8';
            $statusLC = strtolower($a['status']);
        ?>
        <tr>
            <td><?php echo htmlspecialchars($alarmTypeNames[$a['alarm_type']] ?? $a['alarm_type']); ?></td>
            <td><span class="pill" style="background:<?php echo $sevC; ?>22;color:<?php echo $sevC; ?>"><?php echo htmlspecialchars(strtoupper($sev)); ?></span></td>
            <td><span class="pill pill-<?php echo $statusLC; ?>"><?php echo htmlspecialchars(strtoupper($a['status'])); ?></span></td>
            <td class="text-right"><strong><?php echo number_format($a['cnt']); ?></strong></td>
            <td class="dim"><?php echo $a['last_seen'] ? date('d.m.Y H:i', strtotime($a['last_seen'])) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- TAB 6 — SNMP Toplama                                                  -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div id="tab-snmp-collect" class="tab-panel">
<div class="card">
    <div class="card-title"><i class="fas fa-broadcast-tower"></i> SNMP Toplama — Ne Toplanıyor?</div>
    <p class="dim" style="margin-bottom:16px">
        Her polling döngüsünde switch başına hangi SNMP veriler sorgulanıyor.
        Ağır (heavy) etiketli sorguları devre dışı bırakmak hem SNMP yükünü hem DB boyutunu düşürür.
    </p>
    <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(340px,1fr)); gap:14px;">

        <div style="background:var(--dark-mid);border:1px solid var(--border);border-radius:10px;padding:16px;">
            <div style="font-weight:700;margin-bottom:10px;font-size:14px"><i class="fas fa-sync" style="color:#3b82f6"></i> Port Durumu <span class="dim" style="font-size:11px">(her ~15 sn)</span></div>
            <span class="snmp-chip light">ifOperStatus</span>
            <span class="snmp-chip light">ifAdminStatus</span>
            <span class="snmp-chip light">ifName</span>
            <span class="snmp-chip light">ifAlias</span>
            <span class="snmp-chip">ifSpeed</span>
            <span class="snmp-chip">dot1qPvid (VLAN)</span>
            <div class="dim" style="margin-top:8px;font-size:12px">→ <code>port_status_data</code> tablosuna UPSERT — boyut sabit</div>
        </div>

        <div style="background:var(--dark-mid);border:1px solid var(--border);border-radius:10px;padding:16px;">
            <div style="font-weight:700;margin-bottom:10px;font-size:14px"><i class="fas fa-address-book" style="color:#f59e0b"></i> MAC Tablosu <span class="dim" style="font-size:11px">(her ~60 sn)</span></div>
            <span class="snmp-chip heavy">dot1dTpFdbPort (MAC walk)</span>
            <span class="snmp-chip">dot1dTpFdbAddress</span>
            <div class="dim" style="margin-top:8px;font-size:12px">→ MAC değişim tespiti için kullanılır, <code>mac_tracking_history</code>'ye yazılır</div>
            <div class="dim" style="font-size:12px;margin-top:4px;color:#fbbf24">⚠ Büyük switch'lerde 300+ satır walk — ağır!</div>
        </div>

        <div style="background:var(--dark-mid);border:1px solid var(--border);border-radius:10px;padding:16px;">
            <div style="font-weight:700;margin-bottom:10px;font-size:14px"><i class="fas fa-microchip" style="color:#10b981"></i> Cihaz Sağlık Bilgisi <span class="dim" style="font-size:11px">(her ~60 sn)</span></div>
            <span class="snmp-chip">sysDescr</span>
            <span class="snmp-chip">sysUpTime</span>
            <span class="snmp-chip">CPU 1-min (Cisco OID)</span>
            <span class="snmp-chip">Memory Usage</span>
            <span class="snmp-chip">Temperature</span>
            <span class="snmp-chip">PoE Nominal/Consumed</span>
            <span class="snmp-chip">Fan Status</span>
            <div class="dim" style="margin-top:8px;font-size:12px">→ <code>device_polling_data</code>'ya INSERT + <code>snmp_devices</code>'a UPDATE</div>
            <div class="dim" style="font-size:12px;margin-top:4px;color:#f87171">🔴 Bu tablo büyüyor — sıcaklık/fan takibi gerekmiyorsa interval artırılabilir</div>
        </div>

        <div style="background:var(--dark-mid);border:1px solid var(--border);border-radius:10px;padding:16px;">
            <div style="font-weight:700;margin-bottom:10px;font-size:14px"><i class="fas fa-share-alt" style="color:#a78bfa"></i> LLDP Komşu <span class="dim" style="font-size:11px">(her ~60 sn)</span></div>
            <span class="snmp-chip">lldpRemSysName</span>
            <span class="snmp-chip">lldpRemPortId</span>
            <span class="snmp-chip heavy">lldpRemManAddr (walk)</span>
            <div class="dim" style="margin-top:8px;font-size:12px">→ Topoloji görünümü için kullanılır, DB'ye yazılmaz (gerçek zamanlı)</div>
        </div>

        <div style="background:var(--dark-mid);border:1px solid var(--border);border-radius:10px;padding:16px;">
            <div style="font-weight:700;margin-bottom:10px;font-size:14px"><i class="fas fa-traffic-light" style="color:#06b6d4"></i> Trafik Sayaçları <span class="dim" style="font-size:11px">(her ~15 sn)</span></div>
            <span class="snmp-chip">ifInOctets</span>
            <span class="snmp-chip">ifOutOctets</span>
            <span class="snmp-chip">ifInErrors</span>
            <span class="snmp-chip">ifOutErrors</span>
            <div class="dim" style="margin-top:8px;font-size:12px">→ <code>port_status_data</code>'ya UPSERT — boyut sabit</div>
        </div>

    </div>
</div>

<div class="card">
    <div class="card-title"><i class="fas fa-lightbulb" style="color:var(--warning)"></i> DB'yi & SNMP Yükünü Hafifletme Önerileri</div>
    <div style="display:flex;flex-direction:column;gap:10px;">

        <div style="padding:12px;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;">
            <strong style="color:#f87171">1. MySQL event_scheduler'ı aç</strong>
            <pre style="margin-top:6px;background:var(--dark);padding:10px;border-radius:6px;font-size:12px;color:#93c5fd;overflow-x:auto">SET GLOBAL event_scheduler = ON;
-- my.cnf'ye de ekle:
-- event_scheduler = ON</pre>
            <span class="dim">7 günden eski device_polling_data satırlarını otomatik siler.</span>
        </div>

        <div style="padding:12px;background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:8px;">
            <strong style="color:#fbbf24">2. Polling interval'ını artır (config.yml)</strong>
            <pre style="margin-top:6px;background:var(--dark);padding:10px;border-radius:6px;font-size:12px;color:#93c5fd;overflow-x:auto">polling_data_interval: 300   # 60'tan 300'e (5 dak) → 5x daha az satır
mac_poll_interval: 300       # MAC walk sıklığı azalır → SNMP yükü düşer
device_info_poll_interval: 300</pre>
            <span class="dim">Sadece bu değişiklikle günlük satır sayısı 55.000'den 11.000'e düşer.</span>
        </div>

        <div style="padding:12px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:8px;">
            <strong style="color:#93c5fd">3. Eski verileri hemen temizle</strong>
            <pre style="margin-top:6px;background:var(--dark);padding:10px;border-radius:6px;font-size:12px;color:#93c5fd;overflow-x:auto">DELETE FROM device_polling_data WHERE poll_timestamp &lt; DATE_SUB(NOW(), INTERVAL 7 DAY);
DELETE FROM mac_tracking_history WHERE detected_at &lt; DATE_SUB(NOW(), INTERVAL 30 DAY);
DELETE FROM alarms WHERE status IN ('RESOLVED','resolved') AND resolved_at &lt; DATE_SUB(NOW(), INTERVAL 30 DAY);
OPTIMIZE TABLE device_polling_data;
OPTIMIZE TABLE mac_tracking_history;</pre>
        </div>

    </div>
</div>
</div>

<script>
function showTab(id) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
