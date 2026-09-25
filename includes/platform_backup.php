<?php

/**
 * نسخة SQL + رفع كوكل درايف + حزمة وكلاء
 */

function platform_backup_tables()
{
    return array(
        'tenants',
        'admin_users',
        'subscribers',
        'service_plans',
        'subscriptions',
        'invoices',
        'agent_card_prices',
        'agent_card_stock',
        'agent_card_transfers',
        'agent_card_payments',
        'activity_logs',
        'platform_companies',
        'tenant_sas_accounts',
    );
}

function platform_backup_sql($pdo, $tables = null, $tenantId = 0)
{
    if ($tables === null) {
        $tables = platform_backup_tables();
    }
    $tenantId = (int) $tenantId;
    $scoped = $tenantId > 0;
    $out = "-- platform backup " . date('Y-m-d H:i:s') . ($scoped ? (" tenant " . $tenantId) : " full") . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach ($tables as $table) {
        try {
            $chk = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetch();
            if (!$chk) {
                continue;
            }
            if ($scoped && ($table === 'platform_companies' || $table === 'service_plans')) {
                continue;
            }
            $sql = 'SELECT * FROM `' . $table . '`';
            if ($scoped) {
                if ($table === 'tenants') {
                    $sql .= ' WHERE id = ' . $tenantId;
                } elseif ($table === 'invoices' || $table === 'subscriptions') {
                    $sql .= ' WHERE subscriber_id IN (SELECT id FROM subscribers WHERE tenant_id = ' . $tenantId . ')';
                } elseif ($table === 'activity_logs') {
                    $sql .= ' WHERE subscriber_id IN (SELECT id FROM subscribers WHERE tenant_id = ' . $tenantId . ')';
                } else {
                    $col = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote('tenant_id'))->fetch();
                    if (!$col) {
                        continue;
                    }
                    $sql .= ' WHERE tenant_id = ' . $tenantId;
                }
            } else {
                $out .= "\nTRUNCATE TABLE `{$table}`;\n";
            }
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if ($scoped) {
                $out .= "\n-- " . $table . "\n";
            }
            foreach ($rows as $row) {
                $cols = array();
                $vals = array();
                foreach ($row as $k => $v) {
                    $cols[] = '`' . str_replace('`', '``', $k) . '`';
                    $vals[] = ($v === null) ? 'NULL' : $pdo->quote($v);
                }
                $out .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
        } catch (Exception $e) {
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

/**
 * يحفظ ملف SQL محلي ويرفعه لكوكل درايف إن كان مربوط
 * @return array(bool ok, string path, string msg)
 */
function platform_backup_snapshot($pdo, $config, $prefix = 'backup')
{
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $name = preg_replace('/[^a-z0-9_-]/i', '', (string) $prefix);
    if ($name === '') {
        $name = 'backup';
    }
    $path = $dir . '/' . $name . '-' . date('Ymd-His') . '.sql';
    $tid = 0;
    if (!function_exists('is_super_admin_user') || !is_super_admin_user()) {
        $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    }
    $sql = platform_backup_sql($pdo, null, $tid);
    if (@file_put_contents($path, $sql) === false) {
        return array(false, '', 'تعذر كتابة النسخة');
    }
    $msg = 'تم حفظ النسخة محلياً';
    if (function_exists('gdrive_is_ready') && gdrive_is_ready()) {
        list($upOk, $upMsg) = gdrive_upload_file($path, basename($path));
        $msg .= $upOk ? (' — رُفعت لكوكول درايف') : (' — كوكل درايف: ' . $upMsg);
        if (function_exists('activity_log')) {
            activity_log($pdo, null, 'system', 0, 'backup', $upOk ? 'نسخة + كوكل درايف' : 'نسخة محلية', $msg);
        }
    } elseif (function_exists('activity_log')) {
        activity_log($pdo, null, 'system', 0, 'backup', 'نسخة محلية', basename($path));
    }
    return array(true, $path, $msg);
}

function platform_csv_line($row)
{
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $row);
    rewind($fp);
    $line = stream_get_contents($fp);
    fclose($fp);
    return $line;
}

function platform_agents_pack_zip($pdo, $tenantId = 0)
{
    if (!class_exists('ZipArchive')) {
        return array(false, '', 'ZipArchive غير متوفر على السيرفر');
    }
    $tenantId = (int) $tenantId;
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/agents-pack-' . date('Ymd-His') . '.zip';
    $wUser = $tenantId > 0 ? (' WHERE tenant_id = ' . $tenantId) : '';
    $wSub = $tenantId > 0 ? (' WHERE tenant_id = ' . $tenantId) : '';
    $wChild = $tenantId > 0 ? (' WHERE subscriber_id IN (SELECT id FROM subscribers WHERE tenant_id = ' . $tenantId . ')') : '';
    $wTid = $tenantId > 0 ? (' WHERE tenant_id = ' . $tenantId) : '';
    $sets = array(
        'users.csv' => 'SELECT id, username, display_name, password_hash, role, is_active, phone, tenant_id, sas_manager_id, reports_to_user_id, linked_agent_id FROM admin_users' . $wUser . ' ORDER BY id',
        'tenants.csv' => 'SELECT * FROM tenants' . ($tenantId > 0 ? (' WHERE id = ' . $tenantId) : '') . ' ORDER BY id',
        'subscribers.csv' => 'SELECT * FROM subscribers' . $wSub . ' ORDER BY id',
        'invoices.csv' => 'SELECT * FROM invoices' . $wChild . ' ORDER BY id',
        'subscriptions.csv' => 'SELECT * FROM subscriptions' . $wChild . ' ORDER BY id',
        'agent_card_prices.csv' => 'SELECT * FROM agent_card_prices' . $wTid . ' ORDER BY id',
        'agent_card_transfers.csv' => 'SELECT * FROM agent_card_transfers' . $wTid . ' ORDER BY id',
        'agent_card_payments.csv' => 'SELECT * FROM agent_card_payments' . $wTid . ' ORDER BY id',
    );
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return array(false, '', 'تعذر إنشاء الملف');
    }
    foreach ($sets as $file => $sql) {
        $csv = "\xEF\xBB\xBF";
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $rows = array();
        }
        if ($rows) {
            $csv .= platform_csv_line(array_keys($rows[0]));
            foreach ($rows as $r) {
                $csv .= platform_csv_line(array_values($r));
            }
        }
        $zip->addFromString($file, $csv);
    }
    $zip->close();
    return array(true, $path, 'ok');
}

function platform_csv_rows($text)
{
    $text = preg_replace('/^\xEF\xBB\xBF/', '', (string) $text);
    $fp = fopen('php://temp', 'r+');
    fwrite($fp, $text);
    rewind($fp);
    $header = fgetcsv($fp);
    $rows = array();
    if (!$header) {
        fclose($fp);
        return $rows;
    }
    while (($data = fgetcsv($fp)) !== false) {
        if ($data === array(null) || $data === array('')) {
            continue;
        }
        $row = array();
        foreach ($header as $i => $col) {
            $row[trim((string) $col)] = isset($data[$i]) ? $data[$i] : '';
        }
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function platform_table_columns($pdo, $table)
{
    $cols = array();
    try {
        $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        while ($c = $st->fetch(PDO::FETCH_ASSOC)) {
            $cols[$c['Field']] = true;
        }
    } catch (Exception $e) {
    }
    return $cols;
}

function platform_insert_mapped($pdo, $table, $data, $skipId = true)
{
    $cols = platform_table_columns($pdo, $table);
    $fields = array();
    $vals = array();
    $params = array();
    $i = 0;
    foreach ($data as $k => $v) {
        if (!isset($cols[$k])) {
            continue;
        }
        if ($skipId && $k === 'id') {
            continue;
        }
        $ph = ':p' . $i;
        $fields[] = '`' . $k . '`';
        $vals[] = $ph;
        $params[$ph] = ($v === '' || $v === null) ? null : $v;
        $i++;
    }
    if (!$fields) {
        return 0;
    }
    $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', $vals) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $pdo->lastInsertId();
}

/**
 * يستورد حزمة الوكلاء (zip) ويعيد ربط الديون والتفعيلات والأسعار.
 * $forceTenantId > 0 يفرض وكالة واحدة.
 * @return array(bool ok, string msg)
 */
function platform_agents_pack_import($pdo, $zipPath, $forceTenantId = 0)
{
    if (!class_exists('ZipArchive') || !is_file($zipPath)) {
        return array(false, 'الملف غير صالح');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return array(false, 'تعذر فتح الحزمة');
    }
    $files = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $files[basename($name)] = $zip->getFromIndex($i);
    }
    $zip->close();
    $forceTenantId = (int) $forceTenantId;
    $userMap = array();
    $subMap = array();
    $tenantMap = array();
    $nUsers = 0;
    $nSubs = 0;
    $nInv = 0;
    $nAct = 0;
    $nPrice = 0;

    if ($forceTenantId <= 0 && !empty($files['tenants.csv'])) {
        foreach (platform_csv_rows($files['tenants.csv']) as $t) {
            $old = isset($t['id']) ? (int) $t['id'] : 0;
            $name = isset($t['name']) ? trim((string) $t['name']) : '';
            if ($name === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM tenants WHERE name = :n LIMIT 1');
            $st->execute(array(':n' => $name));
            $exist = (int) $st->fetchColumn();
            if ($exist > 0) {
                $tenantMap[$old] = $exist;
                continue;
            }
            unset($t['id']);
            try {
                $newId = platform_insert_mapped($pdo, 'tenants', $t);
                if ($old > 0 && $newId > 0) {
                    $tenantMap[$old] = $newId;
                }
            } catch (Exception $e) {
            }
        }
    }

    if (!empty($files['users.csv'])) {
        foreach (platform_csv_rows($files['users.csv']) as $u) {
            $old = isset($u['id']) ? (int) $u['id'] : 0;
            $username = isset($u['username']) ? trim((string) $u['username']) : '';
            if ($username === '') {
                continue;
            }
            $st = $pdo->prepare('SELECT id, tenant_id FROM admin_users WHERE username = :u LIMIT 1');
            $st->execute(array(':u' => $username));
            $exist = $st->fetch(PDO::FETCH_ASSOC);
            $tid = $forceTenantId > 0 ? $forceTenantId : (isset($u['tenant_id']) ? (int) $u['tenant_id'] : 1);
            if ($forceTenantId <= 0 && isset($tenantMap[(int) $u['tenant_id']])) {
                $tid = (int) $tenantMap[(int) $u['tenant_id']];
            }
            if ($exist) {
                if ($forceTenantId > 0 && (int) $exist['tenant_id'] !== $forceTenantId) {
                    continue;
                }
                $userMap[$old] = (int) $exist['id'];
                $pdo->prepare(
                    'UPDATE admin_users SET display_name = :d, phone = :p, role = :r, is_active = :a, sas_manager_id = :s WHERE id = :id'
                )->execute(array(
                    ':d' => isset($u['display_name']) ? $u['display_name'] : $username,
                    ':p' => isset($u['phone']) ? $u['phone'] : null,
                    ':r' => isset($u['role']) ? $u['role'] : 'agent',
                    ':a' => isset($u['is_active']) ? (int) $u['is_active'] : 1,
                    ':s' => isset($u['sas_manager_id']) && $u['sas_manager_id'] !== '' ? (int) $u['sas_manager_id'] : null,
                    ':id' => (int) $exist['id'],
                ));
                continue;
            }
            $hash = isset($u['password_hash']) ? (string) $u['password_hash'] : '';
            if ($hash === '') {
                continue;
            }
            $row = array(
                'username' => $username,
                'display_name' => isset($u['display_name']) ? $u['display_name'] : $username,
                'password_hash' => $hash,
                'role' => isset($u['role']) ? $u['role'] : 'agent',
                'is_active' => isset($u['is_active']) ? (int) $u['is_active'] : 1,
                'phone' => isset($u['phone']) ? $u['phone'] : null,
                'tenant_id' => $tid,
                'sas_manager_id' => isset($u['sas_manager_id']) && $u['sas_manager_id'] !== '' ? (int) $u['sas_manager_id'] : null,
                'linked_agent_id' => isset($u['linked_agent_id']) && $u['linked_agent_id'] !== '' ? (int) $u['linked_agent_id'] : null,
            );
            try {
                $newId = platform_insert_mapped($pdo, 'admin_users', $row);
                if ($old > 0 && $newId > 0) {
                    $userMap[$old] = $newId;
                    $nUsers++;
                }
            } catch (Exception $e) {
            }
        }
        foreach (platform_csv_rows($files['users.csv']) as $u) {
            $old = isset($u['id']) ? (int) $u['id'] : 0;
            $rep = isset($u['reports_to_user_id']) ? (int) $u['reports_to_user_id'] : 0;
            if ($old <= 0 || $rep <= 0 || !isset($userMap[$old]) || !isset($userMap[$rep])) {
                continue;
            }
            $pdo->prepare('UPDATE admin_users SET reports_to_user_id = :r WHERE id = :id')
                ->execute(array(':r' => $userMap[$rep], ':id' => $userMap[$old]));
        }
    }

    if (!empty($files['subscribers.csv'])) {
        foreach (platform_csv_rows($files['subscribers.csv']) as $s) {
            $old = isset($s['id']) ? (int) $s['id'] : 0;
            $name = isset($s['name']) ? trim((string) $s['name']) : '';
            $phone = isset($s['phone']) ? trim((string) $s['phone']) : '';
            if ($name === '' || $phone === '') {
                continue;
            }
            $tid = $forceTenantId > 0 ? $forceTenantId : (isset($s['tenant_id']) ? (int) $s['tenant_id'] : 1);
            if ($forceTenantId <= 0 && isset($s['tenant_id']) && isset($tenantMap[(int) $s['tenant_id']])) {
                $tid = (int) $tenantMap[(int) $s['tenant_id']];
            }
            $st = $pdo->prepare('SELECT id FROM subscribers WHERE tenant_id = :t AND phone = :p AND name = :n LIMIT 1');
            $st->execute(array(':t' => $tid, ':p' => $phone, ':n' => $name));
            $exist = (int) $st->fetchColumn();
            if ($exist > 0) {
                $subMap[$old] = $exist;
                $note = isset($s['notes']) ? (string) $s['notes'] : '';
                if ($note !== '') {
                    $pdo->prepare('UPDATE subscribers SET notes = :n, address = :a WHERE id = :id')->execute(array(
                        ':n' => $note,
                        ':a' => isset($s['address']) ? $s['address'] : null,
                        ':id' => $exist,
                    ));
                }
                continue;
            }
            $agent = isset($s['agent_user_id']) ? (int) $s['agent_user_id'] : 0;
            if ($agent > 0 && isset($userMap[$agent])) {
                $agent = $userMap[$agent];
            }
            $row = $s;
            unset($row['id']);
            $row['tenant_id'] = $tid;
            $row['agent_user_id'] = $agent > 0 ? $agent : null;
            try {
                $newId = platform_insert_mapped($pdo, 'subscribers', $row);
                if ($old > 0 && $newId > 0) {
                    $subMap[$old] = $newId;
                    $nSubs++;
                }
            } catch (Exception $e) {
            }
        }
    }

    if (!empty($files['subscriptions.csv'])) {
        foreach (platform_csv_rows($files['subscriptions.csv']) as $r) {
            $sid = isset($r['subscriber_id']) ? (int) $r['subscriber_id'] : 0;
            if (!isset($subMap[$sid])) {
                continue;
            }
            $newSid = $subMap[$sid];
            $st = $pdo->prepare(
                'SELECT id FROM subscriptions WHERE subscriber_id = :s AND service_name = :n AND start_date = :a AND end_date = :b LIMIT 1'
            );
            $st->execute(array(
                ':s' => $newSid,
                ':n' => isset($r['service_name']) ? $r['service_name'] : '',
                ':a' => isset($r['start_date']) ? $r['start_date'] : '',
                ':b' => isset($r['end_date']) ? $r['end_date'] : '',
            ));
            if ((int) $st->fetchColumn() > 0) {
                continue;
            }
            unset($r['id']);
            $r['subscriber_id'] = $newSid;
            try {
                if (platform_insert_mapped($pdo, 'subscriptions', $r) > 0) {
                    $nAct++;
                }
            } catch (Exception $e) {
            }
        }
    }

    if (!empty($files['invoices.csv'])) {
        foreach (platform_csv_rows($files['invoices.csv']) as $r) {
            $sid = isset($r['subscriber_id']) ? (int) $r['subscriber_id'] : 0;
            if (!isset($subMap[$sid])) {
                continue;
            }
            $newSid = $subMap[$sid];
            $st = $pdo->prepare(
                'SELECT id FROM invoices WHERE subscriber_id = :s AND amount = :a AND status = :st AND due_date = :d LIMIT 1'
            );
            $st->execute(array(
                ':s' => $newSid,
                ':a' => isset($r['amount']) ? $r['amount'] : 0,
                ':st' => isset($r['status']) ? $r['status'] : 'unpaid',
                ':d' => isset($r['due_date']) ? $r['due_date'] : '',
            ));
            if ((int) $st->fetchColumn() > 0) {
                continue;
            }
            unset($r['id'], $r['subscription_id']);
            $r['subscriber_id'] = $newSid;
            try {
                if (platform_insert_mapped($pdo, 'invoices', $r) > 0) {
                    $nInv++;
                }
            } catch (Exception $e) {
            }
        }
    }

    if (!empty($files['agent_card_prices.csv'])) {
        foreach (platform_csv_rows($files['agent_card_prices.csv']) as $r) {
            $aid = isset($r['agent_user_id']) ? (int) $r['agent_user_id'] : 0;
            if ($aid > 0 && isset($userMap[$aid])) {
                $aid = $userMap[$aid];
            }
            if ($aid <= 0) {
                continue;
            }
            $tid = $forceTenantId > 0 ? $forceTenantId : (isset($r['tenant_id']) ? (int) $r['tenant_id'] : 1);
            unset($r['id']);
            $r['agent_user_id'] = $aid;
            $r['tenant_id'] = $tid;
            try {
                $pdo->prepare(
                    'INSERT INTO agent_card_prices (tenant_id, agent_user_id, profile_id, profile_name, wholesale_price, agent_price)
                     VALUES (:t, :a, :p, :n, :w, :ap)
                     ON DUPLICATE KEY UPDATE wholesale_price = VALUES(wholesale_price), agent_price = VALUES(agent_price)'
                )->execute(array(
                    ':t' => $tid,
                    ':a' => $aid,
                    ':p' => isset($r['profile_id']) ? (int) $r['profile_id'] : 0,
                    ':n' => isset($r['profile_name']) ? $r['profile_name'] : '',
                    ':w' => isset($r['wholesale_price']) ? $r['wholesale_price'] : 0,
                    ':ap' => isset($r['agent_price']) ? $r['agent_price'] : 0,
                ));
                $nPrice++;
            } catch (Exception $e) {
            }
        }
    }

    $mapAgent = function ($id) use ($userMap) {
        $id = (int) $id;
        if ($id > 0 && isset($userMap[$id])) {
            return $userMap[$id];
        }
        return $id;
    };
    foreach (array('agent_card_transfers.csv' => 'agent_card_transfers', 'agent_card_payments.csv' => 'agent_card_payments') as $file => $table) {
        if (empty($files[$file])) {
            continue;
        }
        foreach (platform_csv_rows($files[$file]) as $r) {
            if (isset($r['to_agent_id'])) {
                $r['to_agent_id'] = $mapAgent($r['to_agent_id']);
            }
            if (isset($r['from_agent_id']) && $r['from_agent_id'] !== '') {
                $r['from_agent_id'] = $mapAgent($r['from_agent_id']);
            }
            if (isset($r['agent_user_id'])) {
                $r['agent_user_id'] = $mapAgent($r['agent_user_id']);
            }
            if (isset($r['created_by']) && $r['created_by'] !== '') {
                $r['created_by'] = $mapAgent($r['created_by']);
            }
            if ($forceTenantId > 0) {
                $r['tenant_id'] = $forceTenantId;
            }
            unset($r['id']);
            try {
                platform_insert_mapped($pdo, $table, $r);
            } catch (Exception $e) {
            }
        }
    }

    $msg = 'مستخدمون جدد ' . $nUsers . ' — مشتركين ' . $nSubs . ' — تفعيلات ' . $nAct . ' — ديون/فواتير ' . $nInv . ' — أسعار ' . $nPrice;
    return array(true, $msg);
}

function backup_current_scope()
{
    $tid = function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    if ($tid <= 0) {
        $tid = 1;
    }
    if (function_exists('is_super_admin_user') && is_super_admin_user()) {
        return array('system', 0, 0, 'system');
    }
    if (function_exists('is_agent_user') && is_agent_user()) {
        $me = function_exists('current_admin') ? current_admin() : null;
        $aid = $me ? (int) $me['id'] : 0;
        return array('agent', $tid, $aid, 'a' . $aid);
    }
    return array('tenant', $tid, 0, 't' . $tid);
}

function backup_subscriber_scope_sql($alias)
{
    $a = preg_replace('/[^a-z]/i', '', (string) $alias);
    if ($a === '') {
        $a = 's';
    }
    list($kind, $tid, $aid) = backup_current_scope();
    if ($kind === 'system') {
        return '';
    }
    if ($kind === 'agent') {
        return ' AND ' . $a . '.tenant_id = ' . (int) $tid . ' AND ' . $a . '.agent_user_id = ' . (int) $aid;
    }
    if (function_exists('is_group_manager_user') && is_group_manager_user() && function_exists('subscriber_agent_scope_sql')) {
        return subscriber_agent_scope_sql($a);
    }
    return ' AND ' . $a . '.tenant_id = ' . (int) $tid;
}

function backup_export_root()
{
    $dir = dirname(__DIR__) . '/storage/export';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Deny from all\n");
    }
    return $dir;
}

function backup_prefs_path()
{
    return backup_export_root() . '/prefs.json';
}

function backup_prefs_all()
{
    $path = backup_prefs_path();
    if (!is_file($path)) {
        return array();
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : array();
}

function backup_prefs_get($key)
{
    $all = backup_prefs_all();
    $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : array();
    return array(
        'hours' => isset($row['hours']) ? (int) $row['hours'] : 0,
        'max' => isset($row['max']) ? (int) $row['max'] : 7,
        'last' => isset($row['last']) ? (string) $row['last'] : '',
    );
}

function backup_prefs_set($key, $hours, $max, $last = null)
{
    $all = backup_prefs_all();
    $prev = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : array();
    $prev['hours'] = max(0, min(720, (int) $hours));
    $prev['max'] = max(1, min(60, (int) $max));
    if ($last !== null) {
        $prev['last'] = (string) $last;
    }
    $all[$key] = $prev;
    @file_put_contents(backup_prefs_path(), json_encode($all));
}

function backup_dump_inserts($pdo, $table, $where)
{
    $out = '';
    try {
        $chk = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetch();
        if (!$chk) {
            return '';
        }
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ' . $where)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return '';
    }
    $out .= "\n-- " . $table . "\n";
    foreach ($rows as $row) {
        $cols = array();
        $vals = array();
        foreach ($row as $k => $v) {
            $cols[] = '`' . str_replace('`', '``', $k) . '`';
            $vals[] = ($v === null) ? 'NULL' : $pdo->quote($v);
        }
        $out .= 'REPLACE INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
    }
    return $out;
}

function backup_fresh_sql($pdo)
{
    list($kind, $tid, $aid, $key) = backup_current_scope();
    $stamp = date('Y-m-d H:i:s');
    $out = "-- fresh backup " . $stamp . "\n";
    $out .= "-- scope " . $kind . " tenant " . (int) $tid . " agent " . (int) $aid . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    if ($kind === 'system') {
        foreach (platform_backup_tables() as $table) {
            $out .= backup_dump_inserts($pdo, $table, '');
        }
        if (function_exists('backup_tables')) {
            foreach (array('message_logs') as $extra) {
                $out .= backup_dump_inserts($pdo, $extra, '');
            }
        } else {
            $out .= backup_dump_inserts($pdo, 'message_logs', '');
        }
    } else {
        $subWhere = ($kind === 'agent')
            ? ('WHERE tenant_id = ' . (int) $tid . ' AND agent_user_id = ' . (int) $aid)
            : ('WHERE tenant_id = ' . (int) $tid);
        $out .= backup_dump_inserts($pdo, 'subscribers', $subWhere);
        $ids = 'SELECT id FROM subscribers ' . $subWhere;
        foreach (array('invoices', 'subscriptions', 'message_logs', 'activity_logs') as $table) {
            $out .= backup_dump_inserts($pdo, $table, 'WHERE subscriber_id IN (' . $ids . ')');
        }
        if ($kind === 'tenant') {
            foreach (array('admin_users', 'sas_users_cache', 'agent_card_prices', 'agent_card_stock', 'agent_card_transfers', 'agent_card_payments', 'tenant_sas_accounts') as $table) {
                $out .= backup_dump_inserts($pdo, $table, 'WHERE tenant_id = ' . (int) $tid);
            }
            $out .= backup_dump_inserts($pdo, 'tenants', 'WHERE id = ' . (int) $tid);
        } else {
            $out .= backup_dump_inserts(
                $pdo,
                'sas_users_cache',
                'WHERE tenant_id = ' . (int) $tid . ' AND username IN (SELECT sas_username FROM subscribers ' . $subWhere . ' AND sas_username IS NOT NULL AND sas_username <> "")'
            );
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return array($out, $key, $kind, $tid, $aid);
}

function backup_prune($dir, $max)
{
    $max = max(1, (int) $max);
    $files = glob($dir . '/*.sql');
    if (!$files) {
        return;
    }
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $drop = array_slice($files, $max);
    foreach ($drop as $f) {
        @unlink($f);
    }
}

function backup_write_now($pdo, $prefix = 'manual')
{
    list($sql, $key) = backup_fresh_sql($pdo);
    $dir = backup_export_root() . '/' . $key;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $safe = preg_replace('/[^a-z0-9_-]/i', '', (string) $prefix);
    if ($safe === '') {
        $safe = 'manual';
    }
    $path = $dir . '/' . $safe . '-' . date('Ymd-His') . '.sql';
    if (@file_put_contents($path, $sql) === false) {
        return array(false, '', 'تعذر كتابة النسخة');
    }
    $prefs = backup_prefs_get($key);
    $max = $prefs['max'] > 0 ? $prefs['max'] : 7;
    backup_prune($dir, $max);
    $prefsAll = backup_prefs_all();
    if (!isset($prefsAll[$key])) {
        $prefsAll[$key] = array('hours' => 0, 'max' => $max, 'last' => '');
    }
    $prefsAll[$key]['last'] = date('Y-m-d H:i:s');
    @file_put_contents(backup_prefs_path(), json_encode($prefsAll));
    return array(true, $path, 'تم إنشاء النسخة الآن: ' . basename($path));
}

function backup_list_files()
{
    list(, , , $key) = backup_current_scope();
    $dir = backup_export_root() . '/' . $key;
    $files = is_dir($dir) ? glob($dir . '/*.sql') : array();
    if (!$files) {
        return array();
    }
    usort($files, function ($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    return $files;
}

function backup_file_for_download($name)
{
    $name = basename((string) $name);
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name)) {
        return '';
    }
    list(, , , $key) = backup_current_scope();
    $path = backup_export_root() . '/' . $key . '/' . $name;
    return is_file($path) ? $path : '';
}

function backup_restore_sql($pdo, $sql, $isSuper, $allowFull)
{
    $sql = (string) $sql;
    if (trim($sql) === '') {
        return array(false, 'الملف فاضي');
    }
    $hasTruncate = (stripos($sql, 'TRUNCATE') !== false);
    if ($hasTruncate && !$isSuper) {
        return array(false, 'هذا الملف يمسح جداول كاملة. استرجاعه لأدمن المنصة فقط.');
    }
    if ($hasTruncate && !$allowFull) {
        return array(false, 'فعّل خيار استبدال قاعدة النظام كامل قبل الاسترجاع.');
    }
    $kind = 'legacy';
    $tid = 0;
    $aid = 0;
    if (preg_match('/-- scope (system|tenant|agent) tenant (\d+) agent (\d+)/', $sql, $m)) {
        $kind = $m[1];
        $tid = (int) $m[2];
        $aid = (int) $m[3];
    }
    list($ck, $ct, $ca) = backup_current_scope();
    if ($kind === 'system' && $ck !== 'system') {
        return array(false, 'نسخة النظام كامل يسترجعها أدمن المنصة.');
    }
    if ($kind === 'tenant' && $ck === 'agent') {
        return array(false, 'نسخة الوكالة يسترجعها مدير الوكالة.');
    }
    if ($kind === 'tenant' && $ck === 'tenant' && $ct !== $tid) {
        return array(false, 'النسخة لوكالة ثانية.');
    }
    if ($kind === 'agent' && $ck === 'agent' && $ca !== $aid) {
        return array(false, 'ما تكدر تسترجع نسخة وكيل ثاني.');
    }
    if ($kind === 'agent' && $ck === 'tenant' && $ct !== $tid) {
        return array(false, 'النسخة لوكالة ثانية.');
    }
    backup_write_now($pdo, 'pre-restore');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $parts = preg_split('/;\s*\n/', $sql);
    try {
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || strpos($stmt, '--') === 0) {
                continue;
            }
            if (!$allowFull && stripos($stmt, 'TRUNCATE') === 0) {
                continue;
            }
            if (stripos($stmt, 'INSERT INTO') === 0) {
                $stmt = preg_replace('/^INSERT INTO/i', 'REPLACE INTO', $stmt, 1);
            }
            $head = strtoupper(substr($stmt, 0, 12));
            $okHead = (strpos($head, 'REPLACE') === 0 || strpos($head, 'INSERT') === 0 || strpos($head, 'SET ') === 0 || strpos($head, 'TRUNCATE') === 0);
            if (!$okHead) {
                continue;
            }
            $pdo->exec($stmt);
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Exception $e) {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Exception $e2) {
        }
        return array(false, 'فشل الاسترجاع: ' . $e->getMessage());
    }
    return array(true, 'تم الاسترجاع. نسخة ما قبل الاسترجاع محفوظة في مجلد التصدير.');
}

function backup_auto_tick($pdo)
{
    list(, , , $key) = backup_current_scope();
    $prefs = backup_prefs_get($key);
    $hours = (int) $prefs['hours'];
    if ($hours <= 0) {
        return;
    }
    $last = $prefs['last'] !== '' ? strtotime($prefs['last']) : 0;
    if ($last && (time() - $last) < ($hours * 3600)) {
        return;
    }
    backup_write_now($pdo, 'auto');
}
