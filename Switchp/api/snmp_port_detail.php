<?php
/**
 * snmp_port_detail.php — Tek port için SNMP detay verileri
 * Kullanım: POST JSON {"switch_id": 93, "port": 20}
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

$auth = new Auth($conn);
$auth->requireLogin();

// Release PHP session lock so concurrent AJAX requests are not serialized.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-cache, no-store, must-revalidate");

function parseSnmpValue($value) {
    if (empty($value) || $value === false) return '';
    $value = trim($value);
    if (preg_match('/^"(.*)"$/s', $value, $m)) return $m[1];
    if (preg_match('/^(STRING|INTEGER|Gauge32|Counter32|Counter64|TimeTicks|IpAddress|Hex-STRING|OID|Timeticks):\s*(.+)$/s', $value, $m)) {
        $v = trim($m[2]);
        if (preg_match('/^"(.*)"$/s', $v, $m2)) return $m2[1];
        return $v;
    }
    return $value;
}

function formatMac($v) {
    $v = trim(parseSnmpValue($v));
    $clean = preg_replace('/[^a-fA-F0-9]/', '', $v);
    if (strlen($clean) !== 12) return '';
    return implode(':', str_split($clean, 2));
}

function formatSpeed($s) {
    $s = intval($s);
    if ($s <= 0) return '-';
    if ($s >= 1000000000) return round($s/1000000000).' Gbps';
    if ($s >= 1000000) return round($s/1000000).' Mbps';
    if ($s >= 1000) return round($s/1000).' Kbps';
    return $s.' bps';
}

function formatBytes($b) {
    if (!$b) return '0 B';
    $b = intval($b);
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, 2).' '.$u[$i];
}

function formatUptime($v) {
    $v = parseSnmpValue($v);
    if (empty($v)) return '-';
    // TimeTicks: (12345678) 1:25:31:18.78  or just number
    if (preg_match('/\((\d+)\)/', $v, $m)) $ticks = intval($m[1]);
    else $ticks = intval($v);
    if ($ticks <= 0) return '-';
    $s = intval($ticks / 100);
    $d = intdiv($s, 86400); $s %= 86400;
    $h = intdiv($s, 3600);  $s %= 3600;
    $m = intdiv($s, 60);    $s %= 60;
    return ($d > 0 ? "{$d}g " : '') . sprintf('%02d:%02d:%02d', $h, $m, $s);
}

function duplex($v) {
    $v = intval(parseSnmpValue($v));
    return match($v) { 1=>'unknown', 2=>'half-duplex', 3=>'full-duplex', default=>'-' };
}

function poeClass($v) {
    $v = intval($v);
    return match($v) { 0=>'Sınıf 0', 1=>'Sınıf 1', 2=>'Sınıf 2', 3=>'Sınıf 3', 4=>'Sınıf 4', default=>'-' };
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $switchId = intval($input['switch_id'] ?? 0);
    $portNum  = intval($input['port'] ?? 0);

    if (!$switchId || !$portNum) {
        echo json_encode(['success'=>false,'error'=>'switch_id ve port gerekli']);
        exit;
    }

    // Get switch → SNMP device credentials (also fetch is_virtual for early-out)
    $stmt = $conn->prepare("
        SELECT s.name, s.ip, s.model, s.is_virtual, sd.ip_address, sd.snmp_version,
               sd.snmp_v3_username, sd.snmp_v3_auth_protocol,
               sd.snmp_v3_auth_password, sd.snmp_v3_priv_protocol,
               sd.snmp_v3_priv_password, sd.snmp_community,
               sd.snmp_engine_id,
               sd.model              AS sd_model,
               sd.system_description AS sd_sys_descr,
               s.ports
        FROM switches s
        LEFT JOIN snmp_devices sd ON (s.name = sd.name OR s.ip = sd.ip_address)
        WHERE s.id = ?
    ");
    $stmt->bind_param('i', $switchId);
    $stmt->execute();
    $sw = $stmt->get_result()->fetch_assoc();

    if (!$sw) {
        echo json_encode(['success'=>false,'error'=>'Switch bulunamadı']);
        exit;
    }

    // Virtual switches have no SNMP agent.  Return connection info from the DB
    // directly so the port detail modal shows edge-switch data without timing out.
    if ((int)($sw['is_virtual'] ?? 0) === 1) {
        $portStmt = $conn->prepare("
            SELECT p.port_no, p.oper_status, p.connection_info_preserved,
                   p.device, p.type, p.ip, p.mac,
                   CASE
                       WHEN (p.ip IS NOT NULL AND p.ip != '')
                         OR (p.mac IS NOT NULL AND p.mac != '')
                         OR (p.device IS NOT NULL AND p.device != '' AND p.device != 'BOŞ')
                         OR (p.connection_info IS NOT NULL AND p.connection_info != '')
                       THEN 1 ELSE 0
                   END as is_active
            FROM   ports p
            WHERE  p.switch_id = ? AND p.port_no = ?
            LIMIT 1
        ");
        $portInfo = null;
        if ($portStmt) {
            $portStmt->bind_param('ii', $switchId, $portNum);
            $portStmt->execute();
            $portInfo = $portStmt->get_result()->fetch_assoc();
            $portStmt->close();
        }
        $ciRaw = $portInfo['connection_info_preserved'] ?? '';
        $ci    = json_decode($ciRaw ?: '{}', true) ?: [];
        $operStatus = $portInfo['oper_status'] ?? 'unknown';
        $isActive   = (int)($portInfo['is_active'] ?? 0);
        // When the port has a device connection but oper_status says 'down' or
        // 'unknown' (virtual switches are not SNMP-polled so the field is stale),
        // override to 'up' so the modal shows "Aktif" instead of "Bağlantı Yok".
        if ($isActive && $operStatus !== 'up') {
            $operStatus = 'up';
        }
        echo json_encode([
            'success' => true,
            'virtual' => true,
            'durum'   => ['admin' => 'up', 'oper' => $operStatus],
            'connection_info' => $ci,
            'port_no' => $portNum,
            'device'  => $portInfo['device'] ?? '',
            'type'    => $portInfo['type']   ?? '',
            'ip'      => $portInfo['ip']     ?? '',
            'mac'     => $portInfo['mac']    ?? '',
        ]);
        exit;
    }

    // When snmp_devices has no row, fall back to config.yml credentials.
    // This handles the case where the switch was manually added to the UI
    // before the Python worker ran (so snmp_devices is empty for this switch).
    if (empty($sw['ip_address'])) {
        $creds = getSnmpCredsFromConfig($sw['ip'] ?? '');
        if ($creds) {
            $sw = array_merge($sw, $creds);
        } else {
            echo json_encode(['success'=>false,'error'=>'Bu switch için SNMP yapılandırması yok']);
            exit;
        }
    }

    if (!extension_loaded('snmp')) {
        echo json_encode(['success'=>false,'error'=>'PHP SNMP eklentisi yüklü değil']);
        exit;
    }

    $ip = $sw['ip_address'];
    $i  = $portNum;
    // Use switches.model first, then fall back to the model stored by the Python
    // worker in snmp_devices.model, then fall back to the switch name.  This ensures
    // CBS350 detection works even when the user has not set a model in the switches
    // table (e.g. after deleting and re-adding the SNMP device entry).
    $switchModel = strtolower($sw['model'] ?: $sw['sd_model'] ?: $sw['name'] ?: '');
    $sysDescrDb  = strtolower($sw['sd_sys_descr'] ?? '');

    if ($sw['snmp_version'] === '3') {
        $authProto = (strtoupper($sw['snmp_v3_auth_protocol'] ?? 'SHA') === 'MD5') ? 'MD5' : 'SHA';
        $privProto = (strtoupper($sw['snmp_v3_priv_protocol'] ?? 'AES') === 'DES') ? 'DES' : 'AES';
        $snmp = new SNMP(SNMP::VERSION_3, $ip, $sw['snmp_v3_username'] ?? 'snmpuser');
        // setSecurity order: sec_level, auth_proto, auth_pass, priv_proto, priv_pass, contextName, contextEngineID
        // CBS350 requires the engine_id as contextEngineID (7th param); contextName is '' (6th param).
        $snmp->setSecurity('authPriv', $authProto,
            $sw['snmp_v3_auth_password'] ?? '',
            $privProto, $sw['snmp_v3_priv_password'] ?? '',
            /* contextName */ '', /* contextEngineID */ $sw['snmp_engine_id'] ?? '');
    } else {
        $snmp = new SNMP(SNMP::VERSION_2c, $ip, $sw['snmp_community'] ?? 'public');
    }
    $snmp->valueretrieval = SNMP_VALUE_PLAIN;
    $snmp->quick_print = true;
    $snmp->timeout = 1500000;  // 1,500,000 µs = 1.5 s
    $snmp->retries = 1;

    // For Catalyst/C9200L switches (IOS-XE), ifIndex ≠ port_number.
    // CBS350 uses sequential ifIndex (1-28 = ports 1-28) so no walk needed.
    // C9200L: GigabitEthernet1/0/N can have any ifIndex assigned by firmware.
    $isModernCatalyst = (
        strpos($switchModel, 'c9200') !== false ||
        strpos($switchModel, '9200') !== false ||
        strpos($switchModel, 'c9300') !== false ||
        strpos($switchModel, '9300') !== false
    );
    // CBS350 is a standalone-OS switch; it does not support Cisco IOS-specific MIBs
    // such as CISCO-VLAN-MEMBERSHIP-MIB (vmVlan). Querying those OIDs causes a
    // 3-second timeout per OID and adds ~9 s of latency per port detail open.
    // Detection is based on switches.model first, then snmp_devices.model (sd_model),
    // then snmp_devices.system_description (sd_sys_descr) — so CBS350 is identified
    // correctly even when the user hasn't set a model in the switches table.
    $isCBS350 = (
        strpos($switchModel, 'cbs350')  !== false ||
        strpos($switchModel, 'cbs-350') !== false ||
        strpos($switchModel, 'cbs 350') !== false ||
        strpos($sysDescrDb,  'cbs350')  !== false ||
        strpos($sysDescrDb,  'cbs-350') !== false
    );
    // CBS350 SNMP can be slow per-OID; reduce timeout/retries to keep total latency acceptable.
    // With ~25 OIDs × 0.8s the worst-case is ~20s; typical case is ~2-3s.
    if ($isCBS350) {
        $snmp->timeout = 800000; // 0.8 s per OID — halves per-timeout wait
        $snmp->retries = 0;      // no retry — fast-fail on unresponsive OID
    }
    if ($isModernCatalyst) {
        try {
            $ifDescrWalk = @$snmp->walk('1.3.6.1.2.1.2.2.1.2');
            if ($ifDescrWalk && is_array($ifDescrWalk)) {
                $physPorts = [];
                foreach ($ifDescrWalk as $oid => $descr) {
                    $d = parseSnmpValue($descr);
                    // Match physical data ports with positive port component (excludes GE1/0/0 and sub-ifs)
                    if (preg_match('/^(GigabitEthernet|TenGigabitEthernet|TwoGigabitEthernet)(\d+)\/(\d+)\/(\d+)$/i', $d, $m)
                        && intval($m[4]) > 0) {
                        $parts = explode('.', ltrim((string)$oid, '.'));
                        $ifIdx = intval(end($parts));
                        if ($ifIdx > 0) {
                            $physPorts[$ifIdx] = [
                                'ifName' => $d,
                                'slot'   => intval($m[2]),
                                'sub'    => intval($m[3]),
                                'port'   => intval($m[4]),
                            ];
                        }
                    }
                }
                // Sort by interface name components (slot, subslot, port) —
                // matches Python worker ordering so port_number is consistent.
                uasort($physPorts, function($a, $b) {
                    if ($a['slot'] !== $b['slot']) return $a['slot'] - $b['slot'];
                    if ($a['sub']  !== $b['sub'])  return $a['sub']  - $b['sub'];
                    return $a['port'] - $b['port'];
                });
                $portList = array_keys($physPorts);
                if (isset($portList[$portNum - 1])) {
                    $i = intval($portList[$portNum - 1]); // use actual ifIndex
                }
            }
        } catch (SNMPException $e) {
            // fall back to $portNum if walk fails
        }
    }

    // ── Temel (probe first — if ifDescr returns empty, SNMP auth has failed)
    // Fetch the first OID only; if it fails skip all remaining GETs and fall back
    // to the DB cache immediately.  Without this guard each of the ~13 OIDs would
    // wait for the full 1.5 s timeout → ~20 s hang on every failed CBS350 request.
    //
    // CBS350 SNMPv3 fast path: when the engine_id is not yet stored in snmp_devices
    // the PHP SNMP extension must perform a USM engine-ID discovery round-trip before
    // the first OID can be fetched.  This discovery takes 5-10 s and can leave the
    // PHP SNMP session in a broken state on subsequent requests.  Skip live SNMP
    // entirely for such CBS350 devices and use the DB cache (the Python worker
    // already polls CBS350 on a regular schedule and stores all port data).
    $snmpFailed = $isCBS350 && $sw['snmp_version'] === '3' && empty($sw['snmp_engine_id']);

    if (!$snmpFailed) {
        $ifDescr = parseSnmpValue(@$snmp->get("1.3.6.1.2.1.2.2.1.2.$i"));

        // Early-fail: if ifDescr is empty the switch is unreachable or auth failed.
        // Skip all further SNMP GET calls and let the DB fallback fill in the values.
        $snmpFailed = empty($ifDescr);
    } else {
        $ifDescr = '';
    }

    $ifName = $ifMtu = $ifSpeed = $ifHighSpeed = $ifPhysAddress = null;
    $ifAdminStatus = $ifOperStatus = 0;
    $ifLastChange  = $dot3Duplex   = null;
    $dot1qPvid     = $vmVlan       = 0;

    if (!$snmpFailed) {
        $ifName        = parseSnmpValue(@$snmp->get("1.3.6.1.2.1.31.1.1.1.1.$i"));
        $ifAlias       = parseSnmpValue(@$snmp->get("1.3.6.1.2.1.31.1.1.1.18.$i"));
        $ifMtu         = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.4.$i"));
        $ifSpeed       = @$snmp->get("1.3.6.1.2.1.2.2.1.5.$i");
        $ifHighSpeed   = @$snmp->get("1.3.6.1.2.1.31.1.1.1.15.$i");
        $ifPhysAddress = @$snmp->get("1.3.6.1.2.1.2.2.1.6.$i");

        // ── Durum
        $ifAdminStatus = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.7.$i"));
        $ifOperStatus  = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.8.$i"));
        $ifLastChange  = @$snmp->get("1.3.6.1.2.1.2.2.1.9.$i");
        $dot3Duplex    = @$snmp->get("1.3.6.1.2.1.10.7.2.1.19.$i");

        // ── VLAN
        $dot1qPvid = intval(parseSnmpValue(@$snmp->get("1.3.6.1.2.1.17.7.1.4.5.1.1.$i")));
        // vmVlan is from CISCO-VLAN-MEMBERSHIP-MIB — only supported on IOS-based Catalyst
        // switches, NOT on CBS350 (standalone OS). Querying it on CBS350 causes a 3s timeout.
        $vmVlan = (!$isCBS350)
            ? intval(parseSnmpValue(@$snmp->get("1.3.6.1.4.1.9.9.68.1.2.2.1.2.$i")))
            : 0;
    } else {
        $ifAlias = '';
    }

    // Always query port_status_data — it is polled by the Python worker and is
    // the same authoritative source the Port Connections tab uses to show UP/DOWN.
    $dbStmt = $conn->prepare("
        SELECT psd.*
        FROM port_status_data psd
        JOIN snmp_devices sd ON psd.device_id = sd.id
        JOIN switches s ON (s.name = sd.name OR s.ip = sd.ip_address)
        WHERE s.id = ? AND psd.port_number = ?
        ORDER BY psd.id DESC LIMIT 1
    ");
    $dbStmt->bind_param('ii', $switchId, $portNum);
    $dbStmt->execute();
    $dbRow = $dbStmt->get_result()->fetch_assoc();
    if ($dbRow) {
        if ($snmpFailed) {
            // Full fallback: SNMP is unreachable — use all fields from DB.
            $portLabel = $isModernCatalyst ? "Gi1/0/$portNum" : "GE$portNum";
            $descrLabel = $isModernCatalyst ? "GigabitEthernet1/0/$portNum" : "GE$portNum";
            $ifName        = $dbRow['port_name']  ?: $portLabel;
            $ifAlias       = $dbRow['port_alias']  ?: '';
            $ifDescr       = $dbRow['port_name']  ?: $descrLabel;
            $ifMtu         = intval($dbRow['port_mtu'] ?? 0);
            $ifSpeed       = intval($dbRow['port_speed'] ?? 0);
            $ifHighSpeed   = intval($dbRow['port_speed'] ?? 0);
            $ifHighSpeed   = $ifHighSpeed > 0 ? round($ifHighSpeed / 1000000) : 0;
            $ifPhysAddress = $dbRow['mac_address'] ?? '';
            $dot1qPvid     = intval($dbRow['vlan_id'] ?? 1);
            $inOctets      = intval($dbRow['in_octets']  ?? $dbRow['bytes_in']  ?? 0);
            $outOctets     = intval($dbRow['out_octets'] ?? $dbRow['bytes_out'] ?? 0);
            $inErrors      = intval($dbRow['in_errors']  ?? $dbRow['errors_in'] ?? 0);
            $outErrors     = intval($dbRow['out_errors'] ?? $dbRow['errors_out'] ?? 0);
        } elseif (empty($ifAlias) && !empty($dbRow['port_alias'])) {
            // SNMP succeeded but returned empty ifAlias — use the DB-cached alias
            // (the Python worker stores port_alias from a regular SNMP walk).
            $ifAlias = trim($dbRow['port_alias']);
        }
        // For C9300L/C9200L: ifPhysAddress returns the switch port's own hardware MAC,
        // not the connected device's MAC. Always prefer port_status_data.mac_address
        // (collected from the FDB table by the Python worker) when available.
        $dbMac = !empty($dbRow['mac_address']) ? $dbRow['mac_address'] : '';
        if ($dbMac) {
            $ifPhysAddress = $dbMac;
        }
        // When live SNMP is unreachable fall back to DB admin/oper status.
        // When SNMP is working the values fetched above are the current truth;
        // the DB may be stale from the last worker poll cycle.
        // Map 'up'→1, 'down'→2, anything else ('unknown', 'dormant', …)→4 so that
        // the durum result below renders as 'unknown' rather than 'down'.
        if ($snmpFailed) {
            $ifAdminStatus = ($dbRow['admin_status'] === 'up') ? 1 : (($dbRow['admin_status'] === 'unknown') ? 4 : 2);
            $ifOperStatus  = ($dbRow['oper_status']  === 'up') ? 1 : (($dbRow['oper_status']  === 'unknown') ? 4 : 2);

            // CBS350 quirk: the firmware sometimes reports oper_status=down via SNMP
            // for ports that are physically UP and passing traffic.  The Python worker
            // stores admin_status='down' for CBS350 ports whose ifAdminStatus OID is not
            // returned (maps 'unknown' → 'down').  When port_status_data has a MAC address
            // (i.e. the FDB table had an active entry for this port on the last poll),
            // the device IS connected — override the stale DOWN status to UP.
            if ($isCBS350 && !empty($dbRow['mac_address']) && $ifOperStatus !== 1) {
                $ifOperStatus  = 1; // port has active device → UP
                $ifAdminStatus = 1; // admin UP (CBS350 may not report admin status correctly)
            }
        }
    } elseif ($snmpFailed && $isCBS350) {
        // No port_status_data row found for this CBS350 port (e.g. port_number ifIndex
        // mismatch between DB and UI).  Fall back directly to ports.oper_status which is
        // updated by autosync_service and the Python worker via a separate path.
        $portFallbackStmt = $conn->prepare(
            "SELECT oper_status FROM ports WHERE switch_id = ? AND port_no = ? LIMIT 1"
        );
        if ($portFallbackStmt) {
            $portFallbackStmt->bind_param('ii', $switchId, $portNum);
            $portFallbackStmt->execute();
            $portFallbackRow = $portFallbackStmt->get_result()->fetch_assoc();
            $portFallbackStmt->close();
            if ($portFallbackRow) {
                $fallbackOper = $portFallbackRow['oper_status'] ?? 'unknown';
                $ifOperStatus  = ($fallbackOper === 'up') ? 1 : (($fallbackOper === 'unknown') ? 4 : 2);
                $ifAdminStatus = $ifOperStatus === 1 ? 1 : 2;
            }
        }
    }

    // VLAN untagged walk (non-Catalyst only — bitmap approach works for sequential ifIndex)
    // C9200L: use dot1qPvid / vmVlan directly (already fetched above)
    // CBS350: skip the VLAN walk to prevent slow queries; dot1qPvid from SNMP or DB is enough.
    $portVlans = []; $primaryVlan = 1;
    if ($isModernCatalyst) {
        // C9200L/C9300L: bitmap port-index ≠ ifIndex, so bitmap gives wrong results.
        // Use PVID (indexed by ifIndex) or vmVlan; DB fallback is handled below.
        if ($dot1qPvid > 1) { $primaryVlan = $dot1qPvid; $portVlans = [$dot1qPvid]; }
        elseif ($vmVlan > 1){ $primaryVlan = $vmVlan;    $portVlans = [$vmVlan]; }
    } elseif (!$snmpFailed && !$isCBS350) {
        // Skip VLAN walk when SNMP auth failed or for CBS350 (walk can be very slow
        // on CBS350 due to large VLAN tables; the DB fallback below gives accurate results).
        $untaggedWalk = @$snmp->walk('1.3.6.1.2.1.17.7.1.4.2.1.5');
        if ($untaggedWalk && is_array($untaggedWalk)) {
            foreach ($untaggedWalk as $oid => $mask) {
                if (!preg_match('/\.(\d+)\.(\d+)$/', $oid, $m)) continue;
                $vid = (int)$m[2];
                $bpos = (int)(($i-1)/8); $bit = 7-(($i-1)%8);
                if ($bpos < strlen($mask) && ((ord($mask[$bpos]) >> $bit) & 1)) {
                    $portVlans[] = $vid;
                    if ($vid != 1) $primaryVlan = $vid;
                }
            }
        }
    } elseif ($isCBS350 && !$snmpFailed && $dot1qPvid > 1) {
        // CBS350 with working SNMP: use the already-fetched dot1qPvid (no walk needed).
        $primaryVlan = $dot1qPvid;
        $portVlans   = [$dot1qPvid];
    }
    // Universal DB VLAN fallback: when no VLAN was determined from live SNMP
    // (either SNMP failed or all VLAN OIDs returned ≤1), use the value the Python
    // worker stored — this covers CBS350 SNMP-fail path and any C9x00L edge case.
    if (empty($portVlans) || $primaryVlan <= 1) {
        $dbVlan = isset($dbRow['vlan_id']) ? intval($dbRow['vlan_id']) : 0;
        if ($dbVlan > 1) { $primaryVlan = $dbVlan; $portVlans = [$dbVlan]; }
        elseif (empty($portVlans)) { $portVlans = [1]; }
    }

    // ── PoE (CBS350: ports 1-24 PoE; C9200L-48P: ports 1-48 PoE)
    $poeMaxPort = $isModernCatalyst ? 48 : 24;
    $poeEnabled = null; $poePower = null; $poeClass = null; $poePriority = null;
    if ($portNum <= $poeMaxPort && !$snmpFailed) {
        if ($isModernCatalyst) {
            // C9300L/C9200L: pethPsePortTable is indexed by {module}.{port}, NOT ifIndex.
            // Use module=1 and the sequential port number (1..48).
            // pethPsePortDetectStatus (.6): 3=deliveringPower  2=searching  1=disabled  4=fault
            // pethPsePortPowerConsumption (.10): reported in watts on IOS-XE; store ×1000 (mW)
            //   so power_watt = power_mw/1000 formula below gives the correct watt string.
            // pethPsePortPowerClassifications (.7): IEEE class 0-4.
            $poeIdx    = "1.$portNum";
            $poeDetect = intval(parseSnmpValue(@$snmp->get("1.3.6.1.2.1.105.1.1.1.6.$poeIdx")));
            $poeEnabled = ($poeDetect === 3); // 3 = deliveringPower
            $rawWatts   = intval(parseSnmpValue(@$snmp->get("1.3.6.1.2.1.105.1.1.1.10.$poeIdx")));
            $poePower   = $rawWatts > 0 ? $rawWatts * 1000 : 0;
            $poeClass   = intval(parseSnmpValue(@$snmp->get("1.3.6.1.2.1.105.1.1.1.7.$poeIdx")));
            $poePriority = null; // not exposed per-port via RFC3621 on IOS-XE
        } else {
            // CBS350: ifIndex equals portNum (sequential); use pethPsePortAdminEnable (.3).
            $poeEnabled  = intval(parseSnmpValue(@$snmp->get("1.3.6.1.2.1.105.1.1.1.3.$i"))) == 1;
            $poePower    = intval(@$snmp->get("1.3.6.1.2.1.105.1.1.1.7.$i"));
            $poeClass    = intval(@$snmp->get("1.3.6.1.2.1.105.1.1.1.5.$i"));
            $poePriority = intval(@$snmp->get("1.3.6.1.2.1.105.1.1.1.4.$i"));
        }
    }

    // ── Trafik (only fetch from SNMP when SNMP is working; otherwise already set from DB)
    if (!$snmpFailed) {
        $inOctets    = intval(@$snmp->get("1.3.6.1.2.1.31.1.1.1.6.$i"));  // 64-bit
        $outOctets   = intval(@$snmp->get("1.3.6.1.2.1.31.1.1.1.10.$i")); // 64-bit
        $inUcast     = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.11.$i"));
        $outUcast    = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.17.$i"));
        $inErrors    = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.14.$i"));
        $outErrors   = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.20.$i"));
        $inDiscards  = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.13.$i"));
        $outDiscards = intval(@$snmp->get("1.3.6.1.2.1.2.2.1.19.$i"));
        $fcsErrors   = intval(@$snmp->get("1.3.6.1.2.1.10.7.2.1.2.$i"));
    } else {
        // Values already loaded from DB above; set remaining to 0
        $inUcast = $outUcast = $inDiscards = $outDiscards = $fcsErrors = 0;
    }

    // ── LLDP (neighbor) — only query via SNMP when SNMP is working
    $lldpNeighbor = null;
    if (!$snmpFailed) {
        // Try timeMark=0 first, then walk other timeMarks if nothing found
        $lldpSysName = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.1.1.9.0.$i.1"));
        // Fallback: some CBS350 firmware versions use remoteIndex > 1
        // (e.g. when a stale LLDP entry for a previous neighbor occupies index 1)
        if (!$lldpSysName) {
            for ($ri = 2; $ri <= 5; $ri++) {
                $lldpSysName = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.1.1.9.0.$i.$ri"));
                if ($lldpSysName) break;
            }
        }
        $lldpPortId  = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.1.1.7.0.$i.1"));
        if (!$lldpPortId) $lldpPortId = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.1.1.8.0.$i.1")); // port desc fallback
        $lldpCaps    = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.1.1.12.0.$i.1"));
        $lldpMgmtIp  = parseSnmpValue(@$snmp->get("1.0.8802.1.1.2.1.4.2.1.5.0.$i.1.4.0.0.0.0"));
        // Populate $lldpNeighbor when ANY useful LLDP data is available.
        // Some device pairs only advertise lldpRemPortDesc without lldpRemSysName
        // (e.g. CBS350 ↔ Catalyst 9606), so do NOT gate on $lldpSysName alone.
        if ($lldpSysName || $lldpPortId) {
            $lldpNeighbor = ['system_name'=>$lldpSysName,'port_id'=>$lldpPortId,'capabilities'=>$lldpCaps,'mgmt_ip'=>$lldpMgmtIp];

            // ── Persist LLDP neighbor data when it mentions a core switch ─────
            // This ensures connection_info_preserved is kept fresh for
            // sync_core_ports.php even between Python worker runs.
            // Also resolve the CORESW name from management IP when sysName is absent.
            $resolvedSysName = $lldpSysName;
            if (!$resolvedSysName && $lldpMgmtIp) {
                // Try to identify the remote switch from its management IP
                $ipStmt = $conn->prepare(
                    "SELECT s.name FROM switches s
                     JOIN snmp_devices sd ON (sd.name = s.name OR sd.ip_address = s.ip)
                     WHERE s.is_core = 1 AND LEFT(s.name,1) != '{'
                       AND sd.ip_address = ?
                     LIMIT 1"
                );
                if ($ipStmt) {
                    $ipStmt->bind_param('s', $lldpMgmtIp);
                    $ipStmt->execute();
                    $ipRow = $ipStmt->get_result()->fetch_assoc();
                    $ipStmt->close();
                    if ($ipRow) {
                        $resolvedSysName = $ipRow['name'];
                        // Update $lldpNeighbor with the resolved name for UI display
                        $lldpNeighbor['system_name'] = $resolvedSysName;
                    }
                }
            }
            if ($resolvedSysName && stripos($resolvedSysName, 'CORESW') !== false) {
                $rawLldp = trim($resolvedSysName . ($lldpPortId ? ' | ' . $lldpPortId : ''));
                // Always refresh: write raw LLDP text so sync_core_ports.php
                // can recreate the virtual_core JSON on its next run
                $ciStmt = $conn->prepare(
                    "SELECT p.id, p.connection_info_preserved
                     FROM ports p
                     JOIN switches s ON s.id = p.switch_id
                     WHERE s.id = ? AND p.port_no = ?
                     LIMIT 1"
                );
                if ($ciStmt) {
                    $ciStmt->bind_param('ii', $switchId, $portNum);
                    $ciStmt->execute();
                    $ciRow = $ciStmt->get_result()->fetch_assoc();
                    $ciStmt->close();
                    if ($ciRow) {
                        $updStmt = $conn->prepare(
                            "UPDATE ports SET connection_info_preserved = ? WHERE id = ?"
                        );
                        if ($updStmt) {
                            $updStmt->bind_param('si', $rawLldp, $ciRow['id']);
                            $updStmt->execute();
                            $updStmt->close();
                        }
                    }
                }
            }
        }
    }

    $result = [
        'success' => true,
        'switch_name' => $sw['name'],
        'port' => $i,
        'source' => $snmpFailed ? 'database' : 'snmp',
        'temel' => [
            'descr'   => $ifDescr ?: ($isModernCatalyst ? "GigabitEthernet1/0/$portNum" : "GE$portNum"),
            'name'    => $ifName  ?: ($isModernCatalyst ? "Gi1/0/$portNum" : "GE$portNum"),
            'alias'   => $ifAlias,
            'mtu'     => $ifMtu,
            'speed'   => formatSpeed($ifSpeed),
            'high_speed' => intval($ifHighSpeed) > 0 ? intval($ifHighSpeed).' Mbps' : '-',
            'mac'     => formatMac($ifPhysAddress),
            'fdb_mac' => isset($dbRow) ? (formatMac($dbRow['mac_address'] ?? '') ?: '') : '',
        ],
        'durum' => [
            'admin'       => $ifAdminStatus == 1 ? 'up' : 'down',
            'oper'        => $ifOperStatus  == 1 ? 'up' : ($ifOperStatus == 2 ? 'down' : 'unknown'),
            'last_change' => formatUptime($ifLastChange),
            'duplex'      => duplex($dot3Duplex),
        ],
        'vlan' => [
            'primary' => $primaryVlan,
            'pvid'    => $dot1qPvid,
            'vm_vlan' => $vmVlan,
            'vlans'   => $portVlans,
        ],
        'poe' => ($portNum <= $poeMaxPort) ? [
            'enabled'    => $poeEnabled,
            'power_mw'   => $poePower,
            'power_watt' => $poePower > 0 ? round($poePower/1000,1).' W' : '-',
            'class'      => poeClass($poeClass),
            'priority'   => match($poePriority) { 1=>'critical', 2=>'high', 3=>'low', default=>'low' },
        ] : null,
        'trafik' => [
            'in_bytes'    => formatBytes($inOctets),
            'out_bytes'   => formatBytes($outOctets),
            'in_raw'      => $inOctets,
            'out_raw'     => $outOctets,
            'in_ucast'    => $inUcast,
            'out_ucast'   => $outUcast,
            'in_errors'   => $inErrors,
            'out_errors'  => $outErrors,
            'in_discards' => $inDiscards,
            'out_discards'=> $outDiscards,
            'fcs_errors'  => $fcsErrors,
        ],
        'lldp' => $lldpNeighbor,
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

} catch (Throwable $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
