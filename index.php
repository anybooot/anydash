<?php
declare(strict_types=1);
/**
 * ANYDASH Portal — Single File Edition v3
 * English UI + Fixed admin actions
 */

session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'httponly' => true, 'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');

$DB_FILE = __DIR__ . '/anydash.db';

// ─────────────────────────────────────────────────────────────
//  DATABASE
// ─────────────────────────────────────────────────────────────
try {
    $db = new PDO("sqlite:$DB_FILE");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        ptero_user_id INTEGER DEFAULT NULL,
        is_admin INTEGER DEFAULT 0,
        is_banned INTEGER DEFAULT 0,
        ban_reason TEXT DEFAULT NULL,
        banned_at DATETIME DEFAULT NULL,
        twofa_secret TEXT DEFAULT NULL,
        twofa_enabled INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS nodes (
        node_id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_active INTEGER DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS eggs (
        egg_id INTEGER PRIMARY KEY, nest_id INTEGER NOT NULL,
        name TEXT NOT NULL, docker_image TEXT, startup TEXT,
        variables TEXT, is_active INTEGER DEFAULT 0
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER, username TEXT, action TEXT NOT NULL,
        details TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS free_servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        ptero_server_id INTEGER NOT NULL UNIQUE,
        ptero_identifier TEXT NOT NULL,
        name TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME,
        last_renewed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $cols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['is_banned'=>'INTEGER DEFAULT 0','ban_reason'=>'TEXT','banned_at'=>'DATETIME',
              'twofa_secret'=>'TEXT','twofa_enabled'=>'INTEGER DEFAULT 0'] as $c => $def) {
        if (!in_array($c, $cols)) $db->exec("ALTER TABLE users ADD COLUMN $c $def");
    }

    $defaults = [
        'ptero_url'=>'', 'ptero_admin_key'=>'', 'ptero_client_key'=>'',
        'default_ram'=>'2048', 'default_disk'=>'5120', 'default_cpu'=>'100',
        'default_allocations'=>'2', 'default_databases'=>'1', 'default_backups'=>'2',
        'max_free_per_user'=>'1', 'max_free_total'=>'100',
        'server_description'=>'Free plan — renew regularly to keep it.',
        'renew_enabled'=>'1', 'renew_days'=>'7', 'renew_bonus_days'=>'1',
        'site_name'=>'ANYDASH', 'site_logo'=>'',
        'discord_url'=>'', 'website_url'=>'',
        'terms_url'=>'', 'privacy_url'=>'', 'cookies_url'=>'',
        'theme_default'=>'green', 'recaptcha_site_key'=>'',
    ];
    foreach ($defaults as $k => $v) {
        $db->prepare("INSERT OR IGNORE INTO settings (key,value) VALUES (?,?)")->execute([$k, $v]);
    }
} catch (PDOException $e) {
    die("Database error: " . htmlspecialchars($e->getMessage()));
}

// ─────────────────────────────────────────────────────────────
//  HELPERS
// ─────────────────────────────────────────────────────────────
function gs(string $key) { global $db; $s=$db->prepare("SELECT value FROM settings WHERE key=?"); $s->execute([$key]); $r=$s->fetch(); return $r?$r['value']:null; }
function us(string $key,$val){ global $db; $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)")->execute([$key,(string)$val]); }
function log_action(string $action, string $details = '') {
    global $db;
    $uid = $_SESSION['user_id'] ?? null;
    $uname = $_SESSION['username'] ?? 'guest';
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '?';
    $db->prepare("INSERT INTO activity_logs (user_id,username,action,details,ip) VALUES (?,?,?,?,?)")
       ->execute([$uid, $uname, $action, $details, $ip]);
}
function ptero(string $endpoint, string $method = 'GET', $data = null, bool $client = false) {
    $url = rtrim((string)gs('ptero_url'), '/');
    $key = $client ? gs('ptero_client_key') : gs('ptero_admin_key');
    if (!$url || !$key) return ['error' => 'Panel API not configured.'];
    $base = $client ? '/api/client/' : '/api/application/';
    $ch = curl_init($url . $base . ltrim($endpoint, '/'));
    $headers = ["Authorization: Bearer $key","Accept: application/json","Content-Type: application/json"];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    elseif ($method === 'PATCH') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH'); if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); }
    elseif ($method === 'DELETE') { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE'); }
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => 'cURL: ' . $err];
    $json = $resp ? json_decode($resp, true) : null;
    if ($code >= 400) {
        $msg = $json['errors'][0]['detail'] ?? $json['errors'][0]['meta']['detail'] ?? ('HTTP ' . $code);
        return ['error' => $msg, 'http_code' => $code];
    }
    return $json ?? [];
}
function api_json($data, int $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; }
function require_auth() { if (!isset($_SESSION['user_id'])) api_json(['success' => false, 'message' => 'Unauthorized.'], 401); }
function require_admin() { require_auth(); if (empty($_SESSION['is_admin'])) api_json(['success' => false, 'message' => 'Admin access required.'], 403); }

// ─────────────────────────────────────────────────────────────
//  2FA (TOTP)
// ─────────────────────────────────────────────────────────────
function totp_secret(int $len = 20): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, 31)];
    return $s;
}
function base32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = strtoupper($s); $bin = '';
    foreach (str_split($s) as $c) { $p = strpos($a, $c); if ($p === false) continue; $bin .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bin, 8) as $chunk) { if (strlen($chunk) < 8) break; $out .= chr(bindec($chunk)); }
    return $out;
}
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $key = base32_decode($secret); $t = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $t + $i), $key, true);
        $o = ord($hash[19]) & 0xf;
        $calc = (((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o+1]) & 0xff) << 16) |
                ((ord($hash[$o+2]) & 0xff) << 8) | (ord($hash[$o+3]) & 0xff)) % 1000000;
        if (str_pad((string)$calc, 6, '0', STR_PAD_LEFT) === $code) return true;
    }
    return false;
}

// ─────────────────────────────────────────────────────────────
//  AUTO-SUSPEND
// ─────────────────────────────────────────────────────────────
function check_expired_servers(): void {
    global $db;
    if (gs('renew_enabled') !== '1') return;
    $last = (int)(gs('_exp_check') ?? 0);
    if (time() - $last < 60) return;
    us('_exp_check', (string)time());
    $rows = $db->query("SELECT fs.*, u.username FROM free_servers fs JOIN users u ON u.id = fs.user_id
                        WHERE fs.status='active' AND fs.expires_at IS NOT NULL
                        AND datetime(fs.expires_at) < datetime('now')")->fetchAll();
    foreach ($rows as $row) {
        $r = ptero("servers/{$row['ptero_server_id']}/suspend", 'POST');
        if (!isset($r['error'])) {
            $db->prepare("UPDATE free_servers SET status='expired' WHERE id=?")->execute([$row['id']]);
            log_action('SERVER_EXPIRED', "Auto-suspended: {$row['name']}");
        }
    }
}

// ─────────────────────────────────────────────────────────────
//  API ROUTER
// ─────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    try { check_expired_servers(); } catch (Throwable $e) {}

    // ─── REGISTER ───
    if ($action === 'register') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (!$username || !$email || !$password) api_json(['success' => false, 'message' => 'All fields are required.']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_json(['success' => false, 'message' => 'Invalid email address.']);
        if (strlen($password) < 8) api_json(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) api_json(['success' => false, 'message' => 'Username: 3-32 chars, letters/numbers/underscore only.']);

        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $count = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $is_admin = $count === 0 ? 1 : 0;
        $api_ok = gs('ptero_url') && gs('ptero_admin_key');
        $ptero_uid = null;

        if ($api_ok) {
            $search = ptero('users?filter[email]=' . urlencode($email));
            if (isset($search['data']) && !empty($search['data'])) {
                $existing = $search['data'][0]['attributes'];
                $ptero_uid = $existing['id'];
                ptero("users/$ptero_uid", 'PATCH', [
                    'email' => $email, 'username' => $existing['username'],
                    'first_name' => $existing['first_name'], 'last_name' => $existing['last_name'],
                    'password' => $password,
                ]);
            } else {
                $create = ptero('users', 'POST', [
                    'username' => $username, 'email' => $email,
                    'first_name' => $username, 'last_name' => 'User', 'password' => $password,
                ]);
                if (isset($create['error'])) api_json(['success' => false, 'message' => 'Panel error: ' . $create['error']]);
                $ptero_uid = $create['attributes']['id'] ?? null;
            }
        } elseif (!$is_admin) {
            api_json(['success' => false, 'message' => 'Portal is not configured by admin yet.']);
        }

        try {
            $db->prepare("INSERT INTO users (username,email,password,ptero_user_id,is_admin) VALUES (?,?,?,?,?)")
               ->execute([$username, $email, $hashed, $ptero_uid, $is_admin]);
            log_action('REGISTER', "New account: $username");
            api_json(['success' => true, 'message' => 'Account created! You can now log in.']);
        } catch (PDOException $e) {
            api_json(['success' => false, 'message' => 'Username or email already exists.']);
        }
    }

    // ─── LOGIN ───
    if ($action === 'login') {
        $ident = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        if (!$ident || !$password) api_json(['success' => false, 'message' => 'Fill in all fields.']);

        $s = $db->prepare("SELECT * FROM users WHERE username=? OR email=?");
        $s->execute([$ident, $ident]);
        $user = $s->fetch();

        if (!$user) {
            if (gs('ptero_url') && gs('ptero_admin_key')) {
                $ptero_check = ptero('users?filter[email]=' . urlencode($ident));
                if (!empty($ptero_check['data'])) {
                    api_json(['success' => false, 'message' => 'You have a panel account but no portal account yet. Please register with the same email.']);
                }
            }
            log_action('LOGIN_FAIL', "Unknown user: $ident");
            api_json(['success' => false, 'message' => 'Incorrect username or password.']);
        }

        if (!password_verify($password, $user['password'])) {
            log_action('LOGIN_FAIL', "Wrong password for: $ident");
            api_json(['success' => false, 'message' => 'Incorrect password. If you recently changed it in the panel, use the new one or register again to sync.']);
        }
        if (!empty($user['is_banned'])) {
            api_json(['success' => false, 'message' => 'Your account is banned. Reason: ' . ($user['ban_reason'] ?: 'N/A')]);
        }
        if (!empty($user['twofa_enabled'])) {
            $_SESSION['pending_2fa_user'] = (int)$user['id'];
            api_json(['success' => true, 'needs_2fa' => true, 'message' => 'Enter your 2FA code.']);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (int)$user['is_admin'];
        $_SESSION['ptero_user_id'] = $user['ptero_user_id'];
        log_action('LOGIN', 'Logged in');
        api_json(['success' => true, 'message' => 'Welcome back, ' . $user['username'] . '!']);
    }

    // ─── 2FA VERIFY ───
    if ($action === 'verify_2fa') {
        $code = trim($_POST['code'] ?? '');
        $uid = $_SESSION['pending_2fa_user'] ?? 0;
        if (!$uid) api_json(['success' => false, 'message' => 'No pending 2FA session.']);
        $s = $db->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$uid]);
        $user = $s->fetch();
        if (!$user || empty($user['twofa_secret'])) api_json(['success' => false, 'message' => '2FA not configured.']);
        if (!totp_verify($user['twofa_secret'], $code)) api_json(['success' => false, 'message' => 'Invalid 2FA code.']);
        unset($_SESSION['pending_2fa_user']);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (int)$user['is_admin'];
        $_SESSION['ptero_user_id'] = $user['ptero_user_id'];
        log_action('LOGIN_2FA', 'Logged in with 2FA');
        api_json(['success' => true, 'message' => 'Welcome back, ' . $user['username'] . '!']);
    }

    // ─── LOGOUT / ME ───
    if ($action === 'logout') { if (isset($_SESSION['user_id'])) log_action('LOGOUT',''); session_destroy(); api_json(['success' => true]); }
    if ($action === 'me') {
        if (!isset($_SESSION['user_id'])) api_json(['success' => false], 401);
        $s = $db->prepare("SELECT id,username,email,is_admin,ptero_user_id,twofa_enabled,created_at FROM users WHERE id=?");
        $s->execute([$_SESSION['user_id']]);
        $user = $s->fetch();
        if (!$user) { session_destroy(); api_json(['success' => false], 401); }
        api_json(['success' => true, 'user' => $user]);
    }

    // ═══ PROTECTED ═══
    require_auth();

    if ($action === 'get_servers') {
        $ptero_uid = $_SESSION['ptero_user_id'];
        $max_per_user = (int)gs('max_free_per_user');
        $max_total = (int)gs('max_free_total');
        $mu = $db->prepare("SELECT COUNT(*) FROM free_servers WHERE user_id=? AND status != 'deleted'");
        $mu->execute([$_SESSION['user_id']]);
        $my_used = (int)$mu->fetchColumn();
        $total_used = (int)$db->query("SELECT COUNT(*) FROM free_servers WHERE status != 'deleted'")->fetchColumn();
        $quota = [
            'per_user' => ['used' => $my_used, 'max' => $max_per_user, 'left' => max(0, $max_per_user - $my_used)],
            'total' => ['used' => $total_used, 'max' => $max_total, 'left' => $max_total > 0 ? max(0, $max_total - $total_used) : -1],
        ];
        if (!$ptero_uid) api_json(['success' => true, 'servers' => [], 'quota' => $quota]);

        $res = ptero('servers?per_page=100');
        if (isset($res['error'])) api_json(['success' => false, 'message' => $res['error'], 'servers' => [], 'quota' => $quota]);

        $fs = $db->prepare("SELECT ptero_server_id, expires_at, last_renewed_at, status FROM free_servers WHERE user_id=?");
        $fs->execute([$_SESSION['user_id']]);
        $free_map = [];
        foreach ($fs->fetchAll() as $r) $free_map[(int)$r['ptero_server_id']] = $r;

        $servers = [];
        foreach (($res['data'] ?? []) as $s) {
            if ((int)$s['attributes']['user'] !== (int)$ptero_uid) continue;
            $a = $s['attributes'];
            $is_free = isset($free_map[$a['id']]);
            $servers[] = [
                'id' => $a['id'], 'uuid' => $a['uuid'], 'identifier' => $a['identifier'],
                'name' => $a['name'], 'description' => $a['description'],
                'node' => $a['node'], 'suspended' => $a['suspended'],
                'limits' => $a['limits'], 'feature_limits' => $a['feature_limits'],
                'is_free' => $is_free,
                'free' => $is_free ? [
                    'status' => $free_map[$a['id']]['status'],
                    'expires_at' => $free_map[$a['id']]['expires_at'],
                    'last_renewed_at' => $free_map[$a['id']]['last_renewed_at'],
                ] : null,
            ];
        }
        api_json(['success' => true, 'servers' => $servers, 'quota' => $quota]);
    }

    if ($action === 'get_active_nodes_eggs') {
        $nodes = $db->query("SELECT * FROM nodes WHERE is_active=1")->fetchAll();
        $eggs = $db->query("SELECT * FROM eggs WHERE is_active=1")->fetchAll();
        api_json(['success' => true, 'nodes' => $nodes, 'eggs' => $eggs,
                  'limits' => [
                      'ram' => gs('default_ram'), 'disk' => gs('default_disk'), 'cpu' => gs('default_cpu'),
                      'databases' => gs('default_databases'), 'allocations' => gs('default_allocations'),
                      'backups' => gs('default_backups'),
                  ]]);
    }

    if ($action === 'create_server') {
        $name = trim($_POST['name'] ?? '');
        $node_id = (int)($_POST['node_id'] ?? 0);
        $egg_id = (int)($_POST['egg_id'] ?? 0);
        if (!$name || !$node_id || !$egg_id) api_json(['success' => false, 'message' => 'All fields are required.']);

        $node = $db->prepare("SELECT * FROM nodes WHERE node_id=? AND is_active=1");
        $node->execute([$node_id]);
        if (!$node->fetch()) api_json(['success' => false, 'message' => 'Selected node is not available.']);

        $egg = $db->prepare("SELECT * FROM eggs WHERE egg_id=? AND is_active=1");
        $egg->execute([$egg_id]);
        $egg_data = $egg->fetch();
        if (!$egg_data) api_json(['success' => false, 'message' => 'Selected game type is not available.']);

        $ptero_uid = $_SESSION['ptero_user_id'];
        if (!$ptero_uid) api_json(['success' => false, 'message' => 'Your account is not linked to the panel.']);

        $max = (int)gs('max_free_per_user');
        $mine = $db->prepare("SELECT COUNT(*) FROM free_servers WHERE user_id=? AND status != 'deleted'");
        $mine->execute([$_SESSION['user_id']]);
        if ((int)$mine->fetchColumn() >= $max) api_json(['success' => false, 'message' => "You have reached your limit of $max free server(s)."]);

        $max_total = (int)gs('max_free_total');
        if ($max_total > 0) {
            $total = (int)$db->query("SELECT COUNT(*) FROM free_servers WHERE status != 'deleted'")->fetchColumn();
            if ($total >= $max_total) api_json(['success' => false, 'message' => "The host has reached its global free server limit ($max_total). Please try again later."]);
        }

        $alloc_res = ptero("nodes/$node_id/allocations?per_page=100");
        $alloc_id = null;
        foreach (($alloc_res['data'] ?? []) as $a) {
            if (!$a['attributes']['assigned']) { $alloc_id = $a['attributes']['id']; break; }
        }
        if (!$alloc_id) api_json(['success' => false, 'message' => 'No free ports available on this node.']);

        $environment = ['SERVER_JARFILE' => 'server.jar'];
        $variables = [];
        if (!empty($egg_data['variables'])) $variables = json_decode($egg_data['variables'], true) ?: [];
        if (empty($variables)) {
            $det = ptero("nests/{$egg_data['nest_id']}/eggs/$egg_id?include=variables");
            if (!isset($det['error']) && isset($det['attributes']['relationships']['variables']['data'])) {
                $variables = $det['attributes']['relationships']['variables']['data'];
                $db->prepare("UPDATE eggs SET variables=? WHERE egg_id=?")->execute([json_encode($variables), $egg_id]);
            }
        }
        foreach ($variables as $v) {
            $env = $v['env_variable'] ?? $v['attributes']['env_variable'] ?? null;
            $def = $v['default_value'] ?? $v['attributes']['default_value'] ?? null;
            if (!$env) continue;
            if (($def === '' || $def === null) && stripos($env, 'VERSION') !== false) $def = 'latest';
            if ($def !== null && $def !== '') $environment[$env] = $def;
        }
        if (!isset($environment['SERVER_JARFILE'])) $environment['SERVER_JARFILE'] = 'server.jar';

        $desc = gs('server_description') ?: 'Free plan.';
        $cfg = [
            'name' => $name, 'description' => $desc,
            'user' => (int)$ptero_uid, 'egg' => (int)$egg_id,
            'docker_image' => $egg_data['docker_image'] ?: 'ghcr.io/pterodactyl/yolks:java_17',
            'startup' => $egg_data['startup'] ?: 'java -Xms128M -XX:MaxRAMPercentage=95.0 -jar {{SERVER_JARFILE}}',
            'environment' => $environment,
            'limits' => ['memory' => (int)gs('default_ram'), 'swap' => 0, 'disk' => (int)gs('default_disk'), 'io' => 500, 'cpu' => (int)gs('default_cpu')],
            'feature_limits' => [
                'databases' => (int)gs('default_databases'),
                'backups' => (int)gs('default_backups'),
                'allocations' => (int)gs('default_allocations'),
            ],
            'allocation' => ['default' => $alloc_id],
            'start_on_completion' => true,
        ];
        $res = ptero('servers', 'POST', $cfg);
        if (isset($res['error'])) api_json(['success' => false, 'message' => 'Panel error: ' . $res['error']]);

        $server_id = $res['attributes']['id'] ?? null;
        $identifier = $res['attributes']['identifier'] ?? null;
        if ($server_id) {
            $expires = null;
            if (gs('renew_enabled') === '1') {
                $days = max(1, min(120, (int)gs('renew_days')));
                $expires = date('Y-m-d H:i:s', time() + $days * 86400);
            }
            $db->prepare("INSERT INTO free_servers (user_id, ptero_server_id, ptero_identifier, name, expires_at) VALUES (?,?,?,?,?)")
               ->execute([$_SESSION['user_id'], $server_id, $identifier, $name, $expires]);
            log_action('CREATE_FREE_SERVER', "Server: $name (#$identifier)");
        }
        api_json(['success' => true, 'message' => "Server \"$name\" is being installed."]);
    }

    if ($action === 'renew_server') {
        $sid = (int)($_POST['server_id'] ?? 0);
        $s = $db->prepare("SELECT * FROM free_servers WHERE ptero_server_id=? AND user_id=?");
        $s->execute([$sid, $_SESSION['user_id']]);
        $row = $s->fetch();
        if (!$row) api_json(['success' => false, 'message' => 'Server not found.']);
        if (gs('renew_enabled') !== '1') api_json(['success' => false, 'message' => 'Renewal is disabled.']);

        $renew_days = max(1, min(120, (int)gs('renew_days')));
        $bonus_days = max(0, min(30, (int)gs('renew_bonus_days')));
        $cooldown_s = (int)($renew_days / 2) * 86400;

        $last = strtotime((string)$row['last_renewed_at']);
        if ($last && time() - $last < $cooldown_s) {
            $wait_h = ceil(($cooldown_s - (time() - $last)) / 3600);
            api_json(['success' => false, 'message' => "Cooldown active. Try again in $wait_h hours."]);
        }
        $base = max(time(), strtotime((string)$row['expires_at']) ?: time());
        $new_expires = $base + ($renew_days + $bonus_days) * 86400;
        if ($row['status'] === 'expired') ptero("servers/$sid/unsuspend", 'POST');

        $db->prepare("UPDATE free_servers SET expires_at=?, last_renewed_at=CURRENT_TIMESTAMP, status='active' WHERE id=?")
           ->execute([date('Y-m-d H:i:s', $new_expires), $row['id']]);
        log_action('RENEW_SERVER', "Renewed: {$row['name']}");
        api_json(['success' => true, 'message' => "Renewed! New expiry: " . date('M d, Y', $new_expires)]);
    }

    if ($action === 'delete_server') {
        $sid = (int)($_POST['server_id'] ?? 0);
        if (!$sid) api_json(['success' => false, 'message' => 'Invalid server ID.']);
        $s = $db->prepare("SELECT * FROM free_servers WHERE ptero_server_id=? AND user_id=?");
        $s->execute([$sid, $_SESSION['user_id']]);
        $row = $s->fetch();
        $isAdmin = !empty($_SESSION['is_admin']);
        if (!$row && !$isAdmin) {
            api_json(['success' => false,
                'message' => 'This server was created directly in the panel and can only be deleted by an administrator. Please open a Discord ticket.',
                'ticket' => true]);
        }
        $info = ptero("servers/$sid");
        $name = $info['attributes']['name'] ?? $sid;
        $res = ptero("servers/$sid/force", 'DELETE');
        if (isset($res['error'])) {
            $res2 = ptero("servers/$sid", 'DELETE');
            if (isset($res2['error'])) api_json(['success' => false, 'message' => 'Panel error: ' . $res2['error']]);
        }
        if ($row) $db->prepare("UPDATE free_servers SET status='deleted' WHERE id=?")->execute([$row['id']]);
        log_action('DELETE_SERVER', "Deleted server: $name (#$sid)");
        api_json(['success' => true, 'message' => "Server \"$name\" deleted."]);
    }

    if ($action === 'change_password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $cfm = (string)($_POST['confirm_password'] ?? '');
        if (!$old || !$new || !$cfm) api_json(['success' => false, 'message' => 'All fields are required.']);
        if (strlen($new) < 8) api_json(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        if ($new !== $cfm) api_json(['success' => false, 'message' => 'Passwords do not match.']);
        $s = $db->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        if (!password_verify($old, $u['password'])) api_json(['success' => false, 'message' => 'Current password is incorrect.']);
        $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);
        if ($u['ptero_user_id']) {
            ptero("users/{$u['ptero_user_id']}", 'PATCH', [
                'email' => $u['email'], 'username' => $u['username'],
                'first_name' => $u['username'], 'last_name' => 'User', 'password' => $new,
            ]);
        }
        log_action('CHANGE_PASSWORD', '');
        api_json(['success' => true, 'message' => 'Password updated successfully.']);
    }

    if ($action === '2fa_setup') {
        $secret = totp_secret(20);
        $_SESSION['tmp_2fa_secret'] = $secret;
        $s = $db->prepare("SELECT username,email FROM users WHERE id=?");
        $s->execute([$_SESSION['user_id']]);
        $u = $s->fetch();
        $issuer = rawurlencode(gs('site_name') ?: 'AnyDash');
        $label = rawurlencode($issuer . ':' . $u['email']);
        $uri = "otpauth://totp/$label?secret=$secret&issuer=$issuer&algorithm=SHA1&digits=6&period=30";
        api_json(['success' => true, 'secret' => $secret, 'uri' => $uri]);
    }
    if ($action === '2fa_enable') {
        $code = trim($_POST['code'] ?? '');
        $secret = $_SESSION['tmp_2fa_secret'] ?? null;
        if (!$secret) api_json(['success' => false, 'message' => 'Setup session expired. Try again.']);
        if (!totp_verify($secret, $code)) api_json(['success' => false, 'message' => 'Invalid code. Make sure your device time is correct.']);
        $db->prepare("UPDATE users SET twofa_secret=?, twofa_enabled=1 WHERE id=?")->execute([$secret, $_SESSION['user_id']]);
        unset($_SESSION['tmp_2fa_secret']);
        log_action('2FA_ENABLED', '');
        api_json(['success' => true, 'message' => '2FA enabled successfully.']);
    }
    if ($action === '2fa_disable') {
        $pw = (string)($_POST['password'] ?? '');
        $s = $db->prepare("SELECT password FROM users WHERE id=?");
        $s->execute([$_SESSION['user_id']]);
        if (!password_verify($pw, $s->fetchColumn())) api_json(['success' => false, 'message' => 'Password is incorrect.']);
        $db->prepare("UPDATE users SET twofa_enabled=0, twofa_secret=NULL WHERE id=?")->execute([$_SESSION['user_id']]);
        log_action('2FA_DISABLED', '');
        api_json(['success' => true, 'message' => '2FA disabled.']);
    }

    // ═══ ADMIN ═══
    require_admin();

    if ($action === 'admin_get_settings') {
        $keys = ['ptero_url','ptero_admin_key','ptero_client_key','default_ram','default_disk','default_cpu',
                 'default_allocations','default_databases','default_backups','max_free_per_user','max_free_total',
                 'server_description','renew_enabled','renew_days','renew_bonus_days','site_name','site_logo',
                 'discord_url','website_url','terms_url','privacy_url','cookies_url',
                 'theme_default','recaptcha_site_key'];
        $out = [];
        foreach ($keys as $k) $out[$k] = gs($k);
        api_json(['success' => true, 'settings' => $out]);
    }
    if ($action === 'admin_save_settings') {
        $fields = ['ptero_url','ptero_admin_key','ptero_client_key','default_ram','default_disk','default_cpu',
                   'default_allocations','default_databases','default_backups','max_free_per_user','max_free_total',
                   'server_description','renew_enabled','renew_days','renew_bonus_days','site_name','site_logo',
                   'discord_url','website_url','terms_url','privacy_url','cookies_url',
                   'theme_default','recaptcha_site_key'];
        if (isset($_POST['renew_days'])) { $rd=(int)$_POST['renew_days']; if($rd<1)$rd=1; if($rd>120)$rd=120; $_POST['renew_days']=(string)$rd; }
        if (isset($_POST['renew_bonus_days'])) { $bd=(int)$_POST['renew_bonus_days']; if($bd<0)$bd=0; if($bd>30)$bd=30; $_POST['renew_bonus_days']=(string)$bd; }
        if (isset($_POST['renew_enabled'])) $_POST['renew_enabled'] = $_POST['renew_enabled'] ? '1' : '0';
        foreach ($fields as $f) if (isset($_POST[$f])) us($f, trim((string)$_POST[$f]));
        log_action('ADMIN_SETTINGS', 'Settings updated');
        api_json(['success' => true, 'message' => 'Settings saved successfully.']);
    }
    if ($action === 'admin_sync_ptero') {
        $nodes_res = ptero('nodes');
        $n = 0;
        foreach (($nodes_res['data'] ?? []) as $nd) {
            $db->prepare("INSERT INTO nodes (node_id,name) VALUES (?,?) ON CONFLICT(node_id) DO UPDATE SET name=?")
               ->execute([$nd['attributes']['id'], $nd['attributes']['name'], $nd['attributes']['name']]);
            $n++;
        }
        $nests_res = ptero('nests');
        $e = 0;
        foreach (($nests_res['data'] ?? []) as $nest) {
            $nid = $nest['attributes']['id'];
            $eggs_res = ptero("nests/$nid/eggs?per_page=100");
            if (isset($eggs_res['error'])) continue;
            foreach (($eggs_res['data'] ?? []) as $egg) {
                $egg_id = $egg['attributes']['id'];
                $vars_json = '[]';
                $det = ptero("nests/$nid/eggs/$egg_id?include=variables");
                if (!isset($det['error']) && isset($det['attributes']['relationships']['variables']['data'])) {
                    $vars = [];
                    foreach ($det['attributes']['relationships']['variables']['data'] as $v) {
                        $vars[] = ['env_variable' => $v['attributes']['env_variable'], 'default_value' => $v['attributes']['default_value'] ?? null];
                    }
                    $vars_json = json_encode($vars);
                }
                $db->prepare("INSERT INTO eggs (egg_id,nest_id,name,docker_image,startup,variables)
                    VALUES (?,?,?,?,?,?) ON CONFLICT(egg_id) DO UPDATE SET name=?,docker_image=?,startup=?,variables=?")
                   ->execute([$egg_id, $nid, $egg['attributes']['name'],
                       $egg['attributes']['docker_image'], $egg['attributes']['startup'], $vars_json,
                       $egg['attributes']['name'], $egg['attributes']['docker_image'],
                       $egg['attributes']['startup'], $vars_json]);
                $e++;
            }
        }
        log_action('SYNC_PTERO', "$n nodes, $e eggs");
        api_json(['success' => true, 'message' => "Sync complete: $n nodes, $e game types."]);
    }
    if ($action === 'admin_get_nodes_eggs') {
        api_json(['success' => true,
            'nodes' => $db->query("SELECT * FROM nodes ORDER BY name")->fetchAll(),
            'eggs' => $db->query("SELECT * FROM eggs ORDER BY name")->fetchAll()]);
    }
    if ($action === 'admin_toggle_node') {
        $db->prepare("UPDATE nodes SET is_active=? WHERE node_id=?")->execute([(int)($_POST['status'] ?? 0), (int)($_POST['node_id'] ?? 0)]);
        api_json(['success' => true, 'message' => 'Node updated.']);
    }
    if ($action === 'admin_toggle_egg') {
        $db->prepare("UPDATE eggs SET is_active=? WHERE egg_id=?")->execute([(int)($_POST['status'] ?? 0), (int)($_POST['egg_id'] ?? 0)]);
        api_json(['success' => true, 'message' => 'Game type updated.']);
    }
    if ($action === 'admin_get_users') {
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 15; $offset = ($page - 1) * $limit;
        $where = $search ? "WHERE username LIKE ? OR email LIKE ?" : "";
        $params = $search ? ["%$search%", "%$search%"] : [];
        $t = $db->prepare("SELECT COUNT(*) FROM users $where");
        $t->execute($params);
        $total = (int)$t->fetchColumn();
        $stmt = $db->prepare("SELECT id,username,email,is_admin,is_banned,ban_reason,ptero_user_id,twofa_enabled,created_at FROM users $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        api_json(['success' => true, 'users' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, ceil($total / $limit))]);
    }
    if ($action === 'admin_user_action') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $task = $_POST['task'] ?? '';
        if (!$uid) api_json(['success' => false, 'message' => 'Invalid user ID.']);
        $s = $db->prepare("SELECT * FROM users WHERE id=?");
        $s->execute([$uid]);
        $target = $s->fetch();
        if (!$target) api_json(['success' => false, 'message' => 'User not found.']);

        switch ($task) {
            case 'toggle_admin':
                if ($target['id'] == 1) api_json(['success' => false, 'message' => 'The root admin cannot be modified.']);
                $db->prepare("UPDATE users SET is_admin = CASE WHEN is_admin=1 THEN 0 ELSE 1 END WHERE id=?")->execute([$uid]);
                log_action('USER_TOGGLE_ADMIN', "User #$uid ({$target['username']})");
                api_json(['success' => true, 'message' => 'Admin role updated for ' . $target['username'] . '.']);
            case 'delete':
                if ($target['id'] == 1) api_json(['success' => false, 'message' => 'The root admin cannot be deleted.']);
                if ($target['ptero_user_id']) ptero("users/{$target['ptero_user_id']}", 'DELETE');
                $db->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
                log_action('USER_DELETE', "User #$uid ({$target['username']})");
                api_json(['success' => true, 'message' => 'User "' . $target['username'] . '" deleted.']);
            case 'reset_pass':
                $p = (string)($_POST['password'] ?? '');
                if (strlen($p) < 8) api_json(['success' => false, 'message' => 'Password must be at least 8 characters.']);
                $db->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($p, PASSWORD_BCRYPT), $uid]);
                if ($target['ptero_user_id']) {
                    ptero("users/{$target['ptero_user_id']}", 'PATCH', [
                        'email' => $target['email'], 'username' => $target['username'],
                        'first_name' => $target['username'], 'last_name' => 'User', 'password' => $p,
                    ]);
                }
                log_action('USER_RESET_PASS', "User #$uid ({$target['username']})");
                api_json(['success' => true, 'message' => 'Password reset for "' . $target['username'] . '".']);
            case 'ban':
                if ($target['id'] == 1) api_json(['success' => false, 'message' => 'The root admin cannot be banned.']);
                $reason = trim($_POST['reason'] ?? 'No reason provided.');
                $db->prepare("UPDATE users SET is_banned=1, ban_reason=?, banned_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$reason, $uid]);
                log_action('USER_BAN', "User #$uid ({$target['username']}) — $reason");
                api_json(['success' => true, 'message' => '"' . $target['username'] . '" has been banned.']);
            case 'unban':
                $db->prepare("UPDATE users SET is_banned=0, ban_reason=NULL, banned_at=NULL WHERE id=?")->execute([$uid]);
                log_action('USER_UNBAN', "User #$uid ({$target['username']})");
                api_json(['success' => true, 'message' => '"' . $target['username'] . '" has been unbanned.']);
        }
        api_json(['success' => false, 'message' => 'Unknown action.']);
    }
    if ($action === 'admin_all_servers') {
        $res = ptero('servers?per_page=100');
        if (isset($res['error'])) api_json(['success' => false, 'message' => $res['error'], 'servers' => []]);
        $servers = [];
        foreach (($res['data'] ?? []) as $s) {
            $a = $s['attributes'];
            $servers[] = ['id'=>$a['id'],'identifier'=>$a['identifier'],'name'=>$a['name'],
                          'user'=>$a['user'],'suspended'=>$a['suspended'],'limits'=>$a['limits'],'node'=>$a['node']];
        }
        api_json(['success' => true, 'servers' => $servers]);
    }
    if ($action === 'get_logs') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = 25; $offset = ($page - 1) * $limit;
        $filter = trim($_GET['filter'] ?? '');
        $where = $filter ? "WHERE action LIKE ? OR username LIKE ? OR details LIKE ?" : "";
        $params = $filter ? ["%$filter%", "%$filter%", "%$filter%"] : [];
        $t = $db->prepare("SELECT COUNT(*) FROM activity_logs $where");
        $t->execute($params);
        $total = (int)$t->fetchColumn();
        $stmt = $db->prepare("SELECT * FROM activity_logs $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        api_json(['success' => true, 'logs' => $stmt->fetchAll(), 'total' => $total, 'pages' => max(1, ceil($total / $limit))]);
    }
    if ($action === 'admin_get_free_servers') {
        $rows = $db->query("SELECT fs.*, u.username, u.email FROM free_servers fs JOIN users u ON u.id = fs.user_id
                            WHERE fs.status != 'deleted' ORDER BY fs.id DESC LIMIT 200")->fetchAll();
        api_json(['success' => true, 'servers' => $rows]);
    }
    api_json(['success' => false, 'message' => 'Invalid action.'], 404);
}

// ─────────────────────────────────────────────────────────────
//  PAGE DATA
// ─────────────────────────────────────────────────────────────
$site_name = gs('site_name') ?: 'ANYDASH';
$site_logo = gs('site_logo') ?: '';
$discord = gs('discord_url') ?: '';
$website = gs('website_url') ?: '';
$terms_url = gs('terms_url') ?: '';
$privacy_url = gs('privacy_url') ?: '';
$cookies_url = gs('cookies_url') ?: '';
$theme = gs('theme_default') ?: 'green';
$ptero_url = rtrim((string)gs('ptero_url'), '/');
$is_admin = !empty($_SESSION['is_admin']);
$logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($site_name) ?></title>
<?php if ($site_logo): ?><link rel="icon" href="<?= htmlspecialchars($site_logo) ?>"><?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --accent:#10b981;--accent-2:#059669;--accent-3:#34d399;--accent-soft:rgba(16,185,129,0.12);
  --bg-0:#0a1410;--bg-1:#0e1e16;--bg-2:#132a1e;--bg-3:#1a3a28;
  --surface:rgba(18,38,28,0.72);--surface-2:rgba(24,48,35,0.55);--surface-solid:#14261c;
  --border:rgba(16,185,129,0.16);--border-2:rgba(16,185,129,0.32);
  --text:#f0fdf4;--text-2:#a7d9bd;--text-3:#6b9b7a;
  --success:#34d399;--warning:#fbbf24;--danger:#f87171;
  --radius-sm:10px;--radius:14px;--radius-lg:18px;--radius-xl:24px;
  --shadow:0 12px 40px rgba(0,0,0,0.35);--shadow-lg:0 24px 60px rgba(0,0,0,0.5);
}
[data-theme="blue"]{--accent:#3b82f6;--accent-2:#1d4ed8;--accent-3:#60a5fa;--accent-soft:rgba(59,130,246,0.14);--bg-0:#070b18;--bg-1:#0a1124;--bg-2:#0f1832;--bg-3:#152244;--surface:rgba(15,24,50,0.72);--surface-solid:#0f1832;--border:rgba(59,130,246,0.16);--border-2:rgba(59,130,246,0.35);--text-2:#a8c5f7;--text-3:#6a7fb3}
[data-theme="purple"]{--accent:#a855f7;--accent-2:#7e22ce;--accent-3:#c084fc;--accent-soft:rgba(168,85,247,0.14);--bg-0:#0e0718;--bg-1:#160c24;--bg-2:#1e1232;--bg-3:#2a1a44;--surface:rgba(24,15,50,0.72);--surface-solid:#1e1232;--border:rgba(168,85,247,0.16);--border-2:rgba(168,85,247,0.35);--text-2:#d4b0f5;--text-3:#8a6bb0}
[data-theme="orange"]{--accent:#f97316;--accent-2:#c2410c;--accent-3:#fb923c;--accent-soft:rgba(249,115,22,0.14);--bg-0:#180b02;--bg-1:#221005;--bg-2:#2e1608;--bg-3:#3f1f0c;--surface:rgba(50,25,10,0.72);--surface-solid:#2e1608;--border:rgba(249,115,22,0.16);--border-2:rgba(249,115,22,0.35);--text-2:#fdba74;--text-3:#b08050}
[data-theme="red"]{--accent:#ef4444;--accent-2:#b91c1c;--accent-3:#f87171;--accent-soft:rgba(239,68,68,0.14);--bg-0:#180404;--bg-1:#220606;--bg-2:#2e0a0a;--bg-3:#3f1010;--surface:rgba(50,12,12,0.72);--surface-solid:#2e0a0a;--border:rgba(239,68,68,0.16);--border-2:rgba(239,68,68,0.35);--text-2:#fca5a5;--text-3:#a06060}
[data-theme="dark"]{--accent:#94a3b8;--accent-2:#64748b;--accent-3:#cbd5e1;--accent-soft:rgba(148,163,184,0.14);--bg-0:#050608;--bg-1:#0a0c10;--bg-2:#101318;--bg-3:#181d24;--surface:rgba(20,24,30,0.72);--surface-solid:#14181e;--border:rgba(148,163,184,0.14);--border-2:rgba(148,163,184,0.3);--text-2:#a1a8b5;--text-3:#66707e}
[data-theme="light"]{--accent:#6366f1;--accent-2:#4338ca;--accent-3:#818cf8;--accent-soft:rgba(99,102,241,0.12);--bg-0:#f5f7fd;--bg-1:#eef2fa;--bg-2:#e2e8f3;--bg-3:#d5dceb;--surface:rgba(255,255,255,0.82);--surface-solid:#fff;--border:rgba(15,23,42,0.09);--border-2:rgba(15,23,42,0.18);--text:#0a0e1a;--text-2:#475569;--text-3:#94a3b8}

html,body{height:100%}
body{font-family:'Inter',sans-serif;background:radial-gradient(ellipse at 30% 20%, var(--bg-1), var(--bg-0));
  color:var(--text);line-height:1.6;overflow-x:hidden;transition:background .4s,color .4s;min-height:100vh}
body::before,body::after{content:'';position:fixed;border-radius:50%;filter:blur(100px);z-index:0;pointer-events:none;opacity:.4}
body::before{width:520px;height:520px;background:var(--accent);top:-180px;left:-120px}
body::after{width:620px;height:620px;background:var(--accent-2);bottom:-220px;right:-180px}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border-2);border-radius:4px}
::-webkit-scrollbar-thumb:hover{background:var(--accent)}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);
  backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);box-shadow:var(--shadow)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 18px;
  border-radius:var(--radius);font-size:13px;font-weight:600;border:none;cursor:pointer;
  transition:all .18s;font-family:inherit;text-decoration:none;user-select:none}
.btn:active{transform:scale(.97)}
.btn svg{width:14px;height:14px;flex:none}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent-2));color:#fff;box-shadow:0 8px 22px var(--accent-soft)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 12px 32px var(--accent-soft);filter:brightness(1.1)}
.btn-secondary{background:var(--surface-2);color:var(--text-2);border:1px solid var(--border)}
.btn-secondary:hover{background:var(--surface-solid);color:var(--text);border-color:var(--border-2)}
.btn-danger{background:rgba(248,113,113,0.12);color:var(--danger);border:1px solid rgba(248,113,113,0.25)}
.btn-danger:hover{background:var(--danger);color:#fff}
.btn-warning{background:rgba(251,191,36,0.12);color:var(--warning);border:1px solid rgba(251,191,36,0.25)}
.btn-warning:hover{background:var(--warning);color:#000}
.btn-success{background:rgba(52,211,153,0.12);color:var(--success);border:1px solid rgba(52,211,153,0.25)}
.btn-success:hover{background:var(--success);color:#000}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-full{width:100%}
.btn-lg{padding:13px 22px;font-size:14px}
.btn-icon{width:34px;height:34px;padding:0;border-radius:var(--radius-sm);background:transparent;color:var(--text-3);display:grid;place-items:center}
.btn-icon:hover{color:var(--text);background:var(--surface-2)}
.btn-icon.danger:hover{color:var(--danger);background:rgba(248,113,113,0.1)}

.form-group{margin-bottom:16px}
.form-label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);margin-bottom:7px}
.form-input{width:100%;padding:12px 14px;border-radius:var(--radius);font-size:13.5px;
  background:var(--surface-solid);border:1px solid var(--border);color:var(--text);
  outline:none;transition:all .2s;font-family:inherit}
.form-input:focus{border-color:var(--accent);box-shadow:0 0 0 4px var(--accent-soft)}
.form-input::placeholder{color:var(--text-3)}
textarea.form-input{resize:vertical;min-height:80px}
select.form-input{cursor:pointer;appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b9b7a' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 14px center;padding-right:36px}
select.form-input option{background:var(--surface-solid);color:var(--text)}
.form-hint{font-size:11px;color:var(--text-3);margin-top:5px}

.header{position:sticky;top:0;z-index:100;background:var(--surface);backdrop-filter:blur(20px);border-bottom:1px solid var(--border)}
.header-inner{max-width:1280px;margin:0 auto;padding:0 24px;height:64px;
  display:flex;align-items:center;justify-content:space-between;gap:14px}
.logo{display:flex;align-items:center;gap:12px;cursor:pointer;text-decoration:none}
.logo-icon{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--accent-2),var(--accent-3));
  display:flex;align-items:center;justify-content:center;font-weight:900;font-size:15px;color:#fff;
  box-shadow:0 8px 22px var(--accent-soft);overflow:hidden;flex:none}
.logo-icon img{width:100%;height:100%;object-fit:cover}
.logo-text{font-size:17px;font-weight:800;color:var(--text);letter-spacing:-.3px}
.nav{display:flex;align-items:center;gap:4px}
.nav-btn{padding:8px 14px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;
  color:var(--text-2);background:none;border:none;cursor:pointer;transition:all .18s;
  display:flex;align-items:center;gap:7px;text-decoration:none}
.nav-btn:hover{color:var(--text);background:var(--accent-soft)}
.nav-btn.active{color:var(--accent-3);background:var(--accent-soft)}
.nav-btn.admin-btn{color:var(--warning)}
.nav-btn.admin-btn:hover{background:rgba(251,191,36,0.1)}
.nav-btn.admin-btn.active{color:var(--warning);background:rgba(251,191,36,0.12)}
.nav-divider{width:1px;height:24px;background:var(--border);margin:0 6px}
.top-links{display:flex;align-items:center;gap:6px}
.top-link{padding:7px 12px;border-radius:var(--radius-sm);font-size:12.5px;font-weight:600;
  color:var(--text-2);background:transparent;border:1px solid transparent;
  text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .18s}
.top-link:hover{color:var(--text);background:var(--surface-2);border-color:var(--border)}
.top-link svg{width:13px;height:13px;flex:none}

main{position:relative;z-index:1;padding:32px 24px;max-width:1280px;margin:0 auto;min-height:calc(100vh - 200px)}
.hidden{display:none!important}

.auth-wrap{width:100%;max-width:440px;margin:40px auto 0}
.auth-logo-wrap{text-align:center;margin-bottom:28px}
.auth-logo-big{width:68px;height:68px;border-radius:20px;margin:0 auto 14px;
  background:linear-gradient(135deg,var(--accent-2),var(--accent-3));
  display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:900;color:#fff;
  box-shadow:0 16px 44px var(--accent-soft);overflow:hidden}
.auth-logo-big img{width:100%;height:100%;object-fit:cover}
.auth-title{font-size:26px;font-weight:800;letter-spacing:-.5px;
  background:linear-gradient(90deg,var(--accent-3),var(--accent));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.auth-subtitle{font-size:13px;color:var(--text-3);margin-top:4px}
.auth-card{position:relative;overflow:hidden}
.auth-card-stripe{position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent)}
.auth-tabs{display:flex;border-bottom:1px solid var(--border);padding:0 24px}
.auth-tab{flex:1;padding:16px 10px;text-align:center;font-size:13px;font-weight:600;
  background:none;border:none;color:var(--text-3);cursor:pointer;
  border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .2s}
.auth-tab.active{color:var(--accent-3);border-bottom-color:var(--accent)}
.auth-form{padding:24px}
.notice-box{background:var(--accent-soft);border:1px solid var(--border-2);
  border-radius:var(--radius);padding:12px 14px;margin-bottom:16px}
.notice-box .notice-title{font-size:11px;font-weight:700;color:var(--accent-3);
  text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.notice-box p{font-size:12.5px;color:var(--text-2);line-height:1.55}

.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;
  margin-bottom:28px;flex-wrap:wrap}
.page-title{font-size:28px;font-weight:800;letter-spacing:-.5px}
.page-title span{background:linear-gradient(90deg,var(--accent-3),var(--accent));
  -webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.page-desc{font-size:13.5px;color:var(--text-3);margin-top:4px}

.user-quota{display:flex;align-items:center;gap:20px;padding:16px 20px;
  border-radius:var(--radius-lg);background:var(--surface);border:1px solid var(--border);
  backdrop-filter:blur(20px);margin-bottom:24px;flex-wrap:wrap;
  animation:cardIn .4s cubic-bezier(.4,0,.2,1)}
.user-quota-left{display:flex;align-items:center;gap:14px;min-width:200px;flex:1}
.user-quota-icon{width:44px;height:44px;border-radius:14px;
  background:linear-gradient(135deg,var(--accent),var(--accent-2));
  display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;
  box-shadow:0 8px 22px var(--accent-soft)}
.user-quota-title{font-size:14.5px;font-weight:700;letter-spacing:-.01em}
.user-quota-sub{font-size:12px;color:var(--text-3);margin-top:2px}
.user-quota-bars{display:flex;gap:22px;flex-wrap:wrap}
.quota-bar-wrap{min-width:140px;flex:1}
.quota-bar-lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;
  letter-spacing:.6px;color:var(--text-3);margin-bottom:5px;display:flex;justify-content:space-between;gap:6px}
.quota-bar-track{height:8px;border-radius:999px;background:var(--surface-solid);overflow:hidden;border:1px solid var(--border)}
.quota-bar-fill{height:100%;background:linear-gradient(90deg,var(--accent),var(--accent-3));
  border-radius:999px;transition:width .5s cubic-bezier(.4,0,.2,1);box-shadow:0 0 12px var(--accent-soft)}
.quota-bar-fill.warn{background:linear-gradient(90deg,#f59e0b,#fbbf24);box-shadow:0 0 12px rgba(245,158,11,.4)}
.quota-bar-fill.full{background:linear-gradient(90deg,#ef4444,#f87171);box-shadow:0 0 12px rgba(239,68,68,.4)}
.quota-bar-num{font-size:11px;color:var(--text-2);font-weight:600;margin-top:4px;font-variant-numeric:tabular-nums}

.quota-usage{margin-top:16px;padding:14px 16px;border-radius:var(--radius);
  background:var(--surface-2);border:1px solid var(--border)}
.quota-usage-title{font-size:11px;font-weight:700;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text-3);margin-bottom:10px}
.quota-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.quota-item{padding:10px 14px;border-radius:var(--radius-sm);
  background:var(--surface-solid);border:1px solid var(--border)}
.quota-lbl{font-size:10.5px;font-weight:600;text-transform:uppercase;
  letter-spacing:.5px;color:var(--text-3);margin-bottom:4px}
.quota-val{font-size:18px;font-weight:800;color:var(--accent-3);font-variant-numeric:tabular-nums}
.quota-max{font-size:13px;color:var(--text-3);font-weight:600}

.limit-info{display:flex;gap:12px;padding:12px 14px;border-radius:var(--radius);
  background:var(--accent-soft);border:1px solid var(--border-2);font-size:12.5px;
  color:var(--text-2);line-height:1.55;margin-bottom:14px}
.limit-info-icon{flex-shrink:0;width:28px;height:28px;border-radius:8px;
  background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px}
.limit-info strong{color:var(--accent-3)}

.server-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:20px}
.server-card{position:relative;overflow:hidden;padding:22px;border-radius:var(--radius-lg);
  background:var(--surface);border:1px solid var(--border);
  backdrop-filter:blur(12px);transition:all .3s cubic-bezier(.4,0,.2,1);
  animation:cardIn .4s cubic-bezier(.4,0,.2,1) both}
@keyframes cardIn{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.server-card:hover{border-color:var(--border-2);transform:translateY(-4px);box-shadow:var(--shadow-lg)}
.server-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;
  background:linear-gradient(90deg,transparent,var(--accent),transparent);opacity:.6}
.server-card.free-card::before{background:linear-gradient(90deg,var(--warning),var(--accent),var(--warning));opacity:1}
.free-tag{position:absolute;top:12px;right:12px;padding:3px 10px;border-radius:999px;
  font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;
  background:linear-gradient(135deg,rgba(251,191,36,0.2),rgba(251,191,36,0.08));
  color:var(--warning);border:1px solid rgba(251,191,36,0.35)}
.server-status{display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.8px;padding:4px 10px;border-radius:999px;margin-bottom:12px}
.status-active{background:rgba(52,211,153,0.1);color:var(--success);border:1px solid rgba(52,211,153,0.2)}
.status-suspended{background:rgba(248,113,113,0.1);color:var(--danger);border:1px solid rgba(248,113,113,0.2)}
.status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:pulse-dot 2s infinite}
@keyframes pulse-dot{0%,100%{opacity:1}50%{opacity:.4}}
.server-name{font-size:17px;font-weight:700;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.server-id{font-size:11px;color:var(--text-3);font-family:'JetBrains Mono',monospace}
.server-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1px;margin:14px 0;
  background:var(--border);border-radius:var(--radius);overflow:hidden}
.stat-item{background:var(--bg-1);padding:10px 8px;text-align:center}
.stat-label{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);margin-bottom:4px}
.stat-value{font-size:13.5px;font-weight:700;color:var(--accent-3)}
.expiry-bar{margin-top:12px;padding:10px 14px;border-radius:var(--radius);
  background:var(--surface-2);border:1px solid var(--border);font-size:12px}
.expiry-bar.warn{background:rgba(251,191,36,0.08);border-color:rgba(251,191,36,0.25);color:var(--warning)}
.expiry-bar.danger{background:rgba(248,113,113,0.08);border-color:rgba(248,113,113,0.25);color:var(--danger)}
.expiry-bar .exp-title{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;opacity:.75;margin-bottom:2px}
.expiry-bar .exp-val{font-weight:700;font-size:13px}
.server-actions{display:flex;gap:8px;margin-top:14px}

.empty-state{grid-column:1/-1;padding:70px 24px;text-align:center;
  border:1px dashed var(--border-2);border-radius:var(--radius-xl);
  background:var(--accent-soft);animation:fadeIn .4s}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.empty-icon{width:68px;height:68px;border-radius:20px;margin:0 auto 16px;
  background:var(--accent-soft);display:flex;align-items:center;justify-content:center;color:var(--accent-3)}
.empty-title{font-size:19px;font-weight:700;margin-bottom:8px}
.empty-desc{font-size:13.5px;color:var(--text-3);max-width:380px;margin:0 auto 20px}
.loading-state{grid-column:1/-1;padding:70px;text-align:center;color:var(--text-3);
  display:flex;flex-direction:column;align-items:center;gap:14px}
.spinner{width:40px;height:40px;border-radius:50%;border:3px solid var(--border);
  border-top-color:var(--accent);animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.alert{padding:14px 18px;border-radius:var(--radius);margin-bottom:20px;
  display:flex;align-items:flex-start;gap:12px;font-size:13px}
.alert-warning{background:rgba(251,191,36,0.07);border:1px solid rgba(251,191,36,0.2);color:#fcd34d}
.alert-icon{flex-shrink:0;font-size:16px}
.alert-body{flex:1}
.alert-title{font-weight:700;margin-bottom:2px;color:var(--text)}

.admin-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);
  padding-bottom:14px;flex-wrap:wrap}
.admin-tab{padding:8px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;
  background:none;border:none;color:var(--text-3);cursor:pointer;transition:all .18s;
  display:flex;align-items:center;gap:7px}
.admin-tab.active{color:var(--accent-3);background:var(--accent-soft)}
.admin-tab:hover:not(.active){color:var(--text);background:var(--surface-2)}
.section-title{font-size:15px;font-weight:700;margin-bottom:16px;color:var(--text);
  padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.admin-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px}
.settings-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}

.toggle-row{display:flex;align-items:center;justify-content:space-between;
  padding:11px 14px;border-radius:var(--radius);background:var(--surface-2);
  border:1px solid var(--border);margin-bottom:7px}
.toggle-label{font-size:13px;font-weight:600}
.toggle-sublabel{font-size:11px;color:var(--text-3)}
.toggle{position:relative;display:inline-flex;cursor:pointer}
.toggle input{opacity:0;width:0;height:0}
.toggle-track{width:40px;height:22px;background:var(--surface);border-radius:11px;
  transition:.2s;position:relative;border:1px solid var(--border)}
.toggle-track::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;
  border-radius:50%;background:var(--text-3);transition:.2s}
.toggle input:checked + .toggle-track{background:var(--accent);border-color:var(--accent)}
.toggle input:checked + .toggle-track::after{transform:translateX(18px);background:#fff}

.table-wrap{overflow-x:auto;border-radius:var(--radius);border:1px solid var(--border)}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--accent-soft)}
th{padding:11px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;
  letter-spacing:.8px;color:var(--text-3);border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:12px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:var(--accent-soft)}
.badge-admin{padding:3px 9px;border-radius:999px;font-size:10px;font-weight:700;
  text-transform:uppercase;background:rgba(251,191,36,0.12);color:#fbbf24;
  border:1px solid rgba(251,191,36,0.25)}
.badge-user{padding:3px 9px;border-radius:999px;font-size:10px;font-weight:700;
  text-transform:uppercase;background:var(--accent-soft);color:var(--text-2);
  border:1px solid var(--border)}
.badge-banned{padding:3px 9px;border-radius:999px;font-size:10px;font-weight:700;
  text-transform:uppercase;background:rgba(248,113,113,0.12);color:var(--danger);
  border:1px solid rgba(248,113,113,0.25)}
.toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;
  margin-bottom:16px;flex-wrap:wrap}
.search-input{padding:8px 14px;border-radius:var(--radius);font-size:13px;
  background:var(--surface-solid);border:1px solid var(--border);color:var(--text);
  outline:none;transition:.18s;font-family:inherit;min-width:220px}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
.pagination{display:flex;gap:4px;align-items:center}
.page-btn{min-width:32px;height:32px;padding:0 8px;border-radius:var(--radius-sm);
  font-size:12px;font-weight:600;background:var(--surface-2);border:1px solid var(--border);
  color:var(--text-2);cursor:pointer;transition:.18s}
.page-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.page-btn:hover:not(.active){background:var(--surface-solid);color:var(--text)}
.total-label{font-size:12px;color:var(--text-3)}

.log-entry{display:flex;align-items:flex-start;gap:12px;padding:10px 14px;
  border-bottom:1px solid var(--border);font-size:12px;flex-wrap:wrap}
.log-entry:last-child{border-bottom:none}
.log-action{font-weight:700;font-family:'JetBrains Mono',monospace;color:var(--accent-3);min-width:120px;font-size:11px}
.log-user{color:var(--text-2);font-weight:500;min-width:100px}
.log-details{color:var(--text-3);flex:1;min-width:120px}
.log-ip{color:var(--text-3);font-family:'JetBrains Mono',monospace;font-size:10px}
.log-time{color:var(--text-3);font-size:10px;white-space:nowrap}
.log-header{display:flex;align-items:center;gap:12px;padding:10px 14px;
  background:var(--accent-soft);font-size:10px;font-weight:700;
  text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);
  border-bottom:1px solid var(--border)}

.modal-overlay{position:fixed;inset:0;z-index:200;display:flex;align-items:center;
  justify-content:center;padding:20px;background:rgba(0,0,0,0.7);
  backdrop-filter:blur(12px);opacity:0;pointer-events:none;transition:opacity .25s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{width:100%;max-width:520px;position:relative;overflow:hidden;
  background:var(--surface-solid);border:1px solid var(--border-2);
  border-radius:var(--radius-xl);transform:scale(.95) translateY(10px);
  transition:transform .25s cubic-bezier(.34,1.4,.64,1);max-height:92vh;
  display:flex;flex-direction:column}
.modal-overlay.open .modal{transform:scale(1) translateY(0)}
.modal-stripe{height:3px;background:linear-gradient(90deg,var(--accent-2),var(--accent-3),var(--accent-2));flex:none}
.modal-header{padding:20px 24px 14px;display:flex;align-items:center;
  justify-content:space-between;gap:12px;flex:none}
.modal-title{font-size:17px;font-weight:700}
.modal-body{padding:4px 24px 22px;overflow-y:auto;flex:1}
.modal-footer{display:flex;gap:10px;padding:16px 24px;border-top:1px solid var(--border);
  background:var(--surface-2);flex:none}

.spec-hero{padding:16px;border-radius:var(--radius);margin-bottom:16px;
  background:linear-gradient(135deg,var(--accent-soft),transparent);
  border:1px solid var(--border-2);position:relative;overflow:hidden}
.spec-hero::after{content:'';position:absolute;top:-50%;right:-30%;width:200px;height:200px;
  background:radial-gradient(circle,var(--accent-soft),transparent 70%);border-radius:50%;pointer-events:none}
.spec-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
  color:var(--accent-3);margin-bottom:10px;display:flex;align-items:center;gap:6px}
.spec-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.spec-box{padding:10px;border-radius:var(--radius-sm);background:var(--surface-2);
  border:1px solid var(--border);text-align:center}
.spec-box .spec-ic{font-size:14px;margin-bottom:4px;opacity:.8}
.spec-box .spec-l{font-size:9.5px;font-weight:600;text-transform:uppercase;
  letter-spacing:.4px;color:var(--text-3);margin-bottom:2px}
.spec-box .spec-v{font-size:13px;font-weight:800;color:var(--accent-3)}

.toast-container{position:fixed;bottom:20px;right:20px;z-index:999;display:flex;
  flex-direction:column;gap:10px;max-width:380px;width:100%;pointer-events:none}
.toast{padding:13px 16px;border-radius:var(--radius);font-size:13px;font-weight:500;
  display:flex;align-items:flex-start;gap:11px;pointer-events:all;border:1px solid;
  backdrop-filter:blur(20px);background:var(--surface-solid);
  animation:toastIn .3s cubic-bezier(.16,1,.3,1);box-shadow:var(--shadow-lg)}
@keyframes toastIn{from{transform:translateY(14px);opacity:0}to{transform:none;opacity:1}}
.toast-success{border-color:rgba(52,211,153,0.3)}
.toast-error{border-color:rgba(248,113,113,0.3)}
.toast-info{border-color:var(--border-2)}
.toast-warning{border-color:rgba(251,191,36,0.3)}
.toast-icon{flex-shrink:0;width:18px;height:18px;margin-top:1px}
.toast-success .toast-icon{color:var(--success)}
.toast-error .toast-icon{color:var(--danger)}
.toast-info .toast-icon{color:var(--accent-3)}
.toast-warning .toast-icon{color:var(--warning)}
.toast-msg{flex:1;color:var(--text);line-height:1.5}
.toast-close{background:none;border:none;color:var(--text-3);cursor:pointer;padding:0;font-size:16px;line-height:1}
.toast-close:hover{color:var(--text)}

.theme-picker{display:flex;gap:8px;flex-wrap:wrap}
.theme-dot{width:32px;height:32px;border-radius:50%;cursor:pointer;
  border:2px solid transparent;transition:.2s;position:relative}
.theme-dot:hover{transform:scale(1.15)}
.theme-dot.active{border-color:var(--text);box-shadow:0 0 0 3px var(--accent-soft)}
.theme-dot[data-t="green"]{background:linear-gradient(135deg,#10b981,#059669)}
.theme-dot[data-t="blue"]{background:linear-gradient(135deg,#3b82f6,#1d4ed8)}
.theme-dot[data-t="purple"]{background:linear-gradient(135deg,#a855f7,#7e22ce)}
.theme-dot[data-t="orange"]{background:linear-gradient(135deg,#f97316,#c2410c)}
.theme-dot[data-t="red"]{background:linear-gradient(135deg,#ef4444,#b91c1c)}
.theme-dot[data-t="dark"]{background:linear-gradient(135deg,#94a3b8,#475569)}
.theme-dot[data-t="light"]{background:linear-gradient(135deg,#e2e8f0,#fff);border:2px solid var(--border)}

.link-preview{display:flex;align-items:center;gap:10px;padding:10px 12px;
  border-radius:var(--radius);background:var(--surface-2);border:1px solid var(--border);
  margin-top:8px;font-size:12px;color:var(--text-2)}
.link-preview img{width:32px;height:32px;border-radius:8px;object-fit:cover;flex:none;background:var(--bg-2)}

footer{border-top:1px solid var(--border);padding:22px 24px;margin-top:40px;background:var(--surface);
  backdrop-filter:blur(20px);position:relative;z-index:1}
.footer-inner{max-width:1280px;margin:0 auto;display:flex;align-items:center;
  justify-content:space-between;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--text-3)}
.footer-links{display:flex;gap:4px;flex-wrap:wrap}
.footer-link{color:var(--text-3);text-decoration:none;padding:6px 10px;
  border-radius:8px;transition:all .18s}
.footer-link:hover{color:var(--accent-3);background:var(--accent-soft)}

@media (max-width:640px){
  .top-link span{display:none}
  .top-link{padding:7px 10px}
  .page-header{flex-direction:column}
  .server-grid{grid-template-columns:1fr}
  main{padding:20px 14px}
  .header-inner{padding:0 14px}
  .spec-grid{grid-template-columns:1fr 1fr}
  .admin-grid,.settings-grid,.quota-grid{grid-template-columns:1fr}
  .footer-inner{flex-direction:column;text-align:center}
  .user-quota{flex-direction:column;align-items:stretch}
  .user-quota-bars{flex-direction:column;gap:12px}
}
</style>
</head>
<body>

<?php if ($logged_in): ?>
<header class="header">
  <div class="header-inner">
    <a class="logo" onclick="switchTab('dashboard')">
      <div class="logo-icon">
        <?php if ($site_logo): ?><img src="<?= htmlspecialchars($site_logo) ?>" alt=""><?php else: ?>NH<?php endif; ?>
      </div>
      <span class="logo-text"><?= htmlspecialchars($site_name) ?></span>
    </a>
    <nav class="nav">
      <button class="nav-btn active" id="tab-dashboard" onclick="switchTab('dashboard')">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12H3l9-9 9 9h-2M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg>
        Dashboard
      </button>
      <?php if ($is_admin): ?>
      <button class="nav-btn admin-btn" id="tab-admin" onclick="switchTab('admin')">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
        Admin
      </button>
      <?php endif; ?>
      <div class="nav-divider"></div>
      <div class="top-links">
        <?php if ($discord): ?>
        <a class="top-link" href="<?= htmlspecialchars($discord) ?>" target="_blank" title="Discord">
          <svg fill="currentColor" viewBox="0 0 24 24"><path d="M20.317 4.492c-1.53-.69-3.17-1.2-4.885-1.49a.075.075 0 0 0-.079.036c-.21.369-.444.85-.608 1.23a18.566 18.566 0 0 0-5.487 0 12.36 12.36 0 0 0-.617-1.23A.077.077 0 0 0 8.562 3c-1.714.29-3.354.8-4.885 1.491a.07.07 0 0 0-.032.027C.533 9.093-.32 13.555.099 17.961a.08.08 0 0 0 .031.055 20.03 20.03 0 0 0 6.016 2.985.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.878.075.075 0 0 1-.008-.127c.126-.094.252-.192.372-.291a.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.099.246.198.373.292a.075.075 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.877.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.965 19.965 0 0 0 6.017-2.985.077.077 0 0 0 .032-.054c.5-5.094-.838-9.52-3.549-13.442a.06.06 0 0 0-.031-.028zM8.02 15.278c-1.183 0-2.157-1.069-2.157-2.38 0-1.312.956-2.38 2.157-2.38 1.21 0 2.176 1.077 2.157 2.38 0 1.312-.956 2.38-2.157 2.38zm7.975 0c-1.183 0-2.157-1.069-2.157-2.38 0-1.312.955-2.38 2.157-2.38 1.21 0 2.176 1.077 2.157 2.38 0 1.312-.946 2.38-2.157 2.38z"/></svg>
          <span>Discord</span>
        </a>
        <?php endif; ?>
        <?php if ($ptero_url): ?>
        <a class="top-link" href="<?= htmlspecialchars($ptero_url) ?>" target="_blank" title="Panel">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          <span>Panel</span>
        </a>
        <?php endif; ?>
        <?php if ($website): ?>
        <a class="top-link" href="<?= htmlspecialchars($website) ?>" target="_blank" title="Website">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
          <span>Website</span>
        </a>
        <?php endif; ?>
      </div>
      <div class="nav-divider"></div>
      <button class="btn-icon" onclick="openSettings()" title="Settings">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
      <button class="btn-icon danger" onclick="doLogout()" title="Logout">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      </button>
    </nav>
  </div>
</header>
<?php endif; ?>

<main>

<?php if (!$logged_in): ?>
<div class="auth-wrap">
  <div class="auth-logo-wrap">
    <div class="auth-logo-big">
      <?php if ($site_logo): ?><img src="<?= htmlspecialchars($site_logo) ?>" alt=""><?php else: ?>NH<?php endif; ?>
    </div>
    <h1 class="auth-title"><?= htmlspecialchars($site_name) ?></h1>
    <p class="auth-subtitle">Game Server Hosting Portal</p>
  </div>
  <div class="card auth-card">
    <div class="auth-card-stripe"></div>
    <div class="auth-tabs">
      <button class="auth-tab active" id="atab-login" onclick="authTab('login')">Sign In</button>
      <button class="auth-tab" id="atab-register" onclick="authTab('register')">Create Account</button>
    </div>

    <form class="auth-form" id="form-login" onsubmit="doAuth(event,'login')">
      <div class="form-group">
        <label class="form-label">Username or Email</label>
        <input type="text" name="username" class="form-input" placeholder="your_username" required autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-full btn-lg">Sign In</button>
    </form>

    <form class="auth-form hidden" id="form-2fa" onsubmit="doVerify2fa(event)">
      <div class="notice-box">
        <div class="notice-title">Two-Factor Authentication</div>
        <p>Enter the 6-digit code from your authenticator app.</p>
      </div>
      <div class="form-group">
        <label class="form-label">6-Digit Code</label>
        <input type="text" name="code" class="form-input" placeholder="000000" required maxlength="6" pattern="\d{6}"
               style="text-align:center;font-family:'JetBrains Mono',monospace;font-size:20px;letter-spacing:.4em">
      </div>
      <button type="submit" class="btn btn-primary btn-full btn-lg">Verify</button>
      <button type="button" class="btn btn-secondary btn-full" onclick="authTab('login')" style="margin-top:8px">Back</button>
    </form>

    <form class="auth-form hidden" id="form-register" onsubmit="doAuth(event,'register')">
      <div class="notice-box">
        <div class="notice-title">Already Have a Panel Account?</div>
        <p>Use the same <strong>email</strong> and <strong>password</strong> from the panel to link your accounts instantly.</p>
      </div>
      <div class="form-group">
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-input" placeholder="my_username" required autocomplete="username">
        <div class="form-hint">3–32 characters, letters / numbers / underscore</div>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-input" placeholder="you@example.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-input" placeholder="Minimum 8 characters" required autocomplete="new-password" minlength="8">
      </div>
      <button type="submit" class="btn btn-primary btn-full btn-lg">Create Account</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($logged_in): ?>
<div id="view-dashboard" class="hidden">
  <div id="ptero-warning" class="alert alert-warning" style="display:none">
    <div class="alert-icon">⚠️</div>
    <div class="alert-body">
      <div class="alert-title">Panel Not Configured</div>
      Configure the panel URL and API key in Admin → Settings to enable server deployment.
    </div>
    <?php if ($is_admin): ?><button class="btn btn-warning btn-sm" onclick="switchTab('admin')">Configure</button><?php endif; ?>
  </div>

  <div class="user-quota" id="user-quota" style="display:none">
    <div class="user-quota-left">
      <div class="user-quota-icon">🎁</div>
      <div>
        <div class="user-quota-title">Free Server Quota</div>
        <div class="user-quota-sub" id="user-quota-sub">—</div>
      </div>
    </div>
    <div class="user-quota-bars">
      <div class="quota-bar-wrap">
        <div class="quota-bar-lbl">Your servers</div>
        <div class="quota-bar-track"><div class="quota-bar-fill" id="quota-bar-mine" style="width:0%"></div></div>
        <div class="quota-bar-num" id="quota-bar-mine-num">0/0</div>
      </div>
      <div class="quota-bar-wrap">
        <div class="quota-bar-lbl">Global total</div>
        <div class="quota-bar-track"><div class="quota-bar-fill" id="quota-bar-total" style="width:0%"></div></div>
        <div class="quota-bar-num" id="quota-bar-total-num">0/0</div>
      </div>
    </div>
  </div>

  <div class="page-header">
    <div>
      <h1 class="page-title">Your <span>Servers</span></h1>
      <p class="page-desc">Manage your servers, renew free hosting, or deploy a new one.</p>
    </div>
    <button class="btn btn-primary" onclick="openCreateModal()">
      <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
      Deploy Free Server
    </button>
  </div>

  <div class="server-grid" id="servers-grid">
    <div class="loading-state"><div class="spinner"></div><span>Loading your servers…</span></div>
  </div>
</div>

<?php if ($is_admin): ?>
<div id="view-admin" class="hidden">
  <div class="page-header">
    <div>
      <h1 class="page-title" style="color:var(--warning)">Admin <span style="background:linear-gradient(90deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">Center</span></h1>
      <p class="page-desc">Configure the portal, manage users, servers and resources.</p>
    </div>
  </div>

  <div class="admin-tabs">
    <button class="admin-tab active" id="atab-settings" onclick="adminTab('settings')">⚙️ Settings</button>
    <button class="admin-tab" id="atab-nodes" onclick="adminTab('nodes')">🌐 Nodes & Games</button>
    <button class="admin-tab" id="atab-users" onclick="adminTab('users')">👥 Users</button>
    <button class="admin-tab" id="atab-allservers" onclick="adminTab('allservers')">🖥️ All Servers</button>
    <button class="admin-tab" id="atab-freeservers" onclick="adminTab('freeservers')">🎁 Free Servers</button>
    <button class="admin-tab" id="atab-logs" onclick="adminTab('logs')">📋 Logs</button>
  </div>

  <div id="apanel-settings">
    <div class="admin-grid">
      <div class="card" style="padding:22px">
        <div class="section-title">Panel Connection</div>
        <form onsubmit="saveSettings(event)">
          <div class="form-group">
            <label class="form-label">Panel URL</label>
            <input type="url" name="ptero_url" id="s-url" class="form-input" placeholder="https://panel.yourdomain.com">
          </div>
          <div class="form-group">
            <label class="form-label">Application API Key</label>
            <input type="password" name="ptero_admin_key" id="s-akey" class="form-input" placeholder="ptla_••••">
          </div>
          <div class="form-group">
            <label class="form-label">Client API Key</label>
            <input type="password" name="ptero_client_key" id="s-ckey" class="form-input" placeholder="ptlc_••••">
          </div>

          <div class="section-title" style="font-size:13px;margin-top:20px">🎮 Free Plan Resources</div>
          <div class="settings-grid">
            <div class="form-group"><label class="form-label">RAM (MB)</label><input type="number" name="default_ram" id="s-ram" class="form-input" min="128" placeholder="2048"></div>
            <div class="form-group"><label class="form-label">Disk (MB)</label><input type="number" name="default_disk" id="s-disk" class="form-input" min="256" placeholder="5120"></div>
            <div class="form-group"><label class="form-label">CPU %</label><input type="number" name="default_cpu" id="s-cpu" class="form-input" min="10" placeholder="100"></div>
            <div class="form-group"><label class="form-label">Allocations</label><input type="number" name="default_allocations" id="s-alloc" class="form-input" min="1" placeholder="2"></div>
            <div class="form-group"><label class="form-label">Databases</label><input type="number" name="default_databases" id="s-db" class="form-input" min="0" placeholder="1"></div>
            <div class="form-group"><label class="form-label">Backups</label><input type="number" name="default_backups" id="s-backups" class="form-input" min="0" placeholder="2"></div>
          </div>

          <div class="section-title" style="font-size:13px;margin-top:20px">🎁 Free Server Limits</div>
          <div class="limit-info">
            <div class="limit-info-icon">💡</div>
            <div>
              <strong>Max per user</strong> — how many free servers each user can create.<br>
              <strong>Max total</strong> — how many free servers can exist across the entire host.
            </div>
          </div>
          <div class="settings-grid">
            <div class="form-group">
              <label class="form-label">Max per User</label>
              <input type="number" name="max_free_per_user" id="s-maxuser" class="form-input" min="1" placeholder="1">
              <div class="form-hint">Per-user limit</div>
            </div>
            <div class="form-group">
              <label class="form-label">Max Total (all users)</label>
              <input type="number" name="max_free_total" id="s-maxtotal" class="form-input" min="1" placeholder="100">
              <div class="form-hint">Global host limit</div>
            </div>
          </div>

          <div class="quota-usage" id="quota-usage">
            <div class="quota-usage-title">📊 Current Usage</div>
            <div class="quota-grid">
              <div class="quota-item">
                <div class="quota-lbl">Your servers</div>
                <div class="quota-val"><span id="usage-mine">—</span> <span class="quota-max">/ <span id="usage-mine-max">—</span></span></div>
              </div>
              <div class="quota-item">
                <div class="quota-lbl">Global total</div>
                <div class="quota-val"><span id="usage-total">—</span> <span class="quota-max">/ <span id="usage-total-max">—</span></span></div>
              </div>
            </div>
          </div>

          <div class="form-group" style="margin-top:16px"><label class="form-label">Default Description</label><textarea name="server_description" id="s-desc" class="form-input"></textarea></div>

          <div class="section-title" style="font-size:13px;margin-top:20px">Free Server Renewal</div>
          <div class="form-group">
            <label class="form-label">Renewal System</label>
            <select name="renew_enabled" id="s-renew-en" class="form-input">
              <option value="1">ON — Servers expire & must be renewed</option>
              <option value="0">OFF — Servers never expire</option>
            </select>
          </div>
          <div class="settings-grid">
            <div class="form-group">
              <label class="form-label">Renew Interval (days)</label>
              <input type="number" name="renew_days" id="s-renew-days" class="form-input" min="1" max="120" placeholder="7">
              <div class="form-hint">1 to 120 days</div>
            </div>
            <div class="form-group">
              <label class="form-label">Bonus days on renew</label>
              <input type="number" name="renew_bonus_days" id="s-renew-bonus" class="form-input" min="0" max="30" placeholder="1">
              <div class="form-hint">Extra days added per renew</div>
            </div>
          </div>
          <div class="form-hint" style="margin-bottom:14px">Cooldown between renewals = half the interval.</div>

          <button type="submit" class="btn btn-primary btn-full">Save Settings</button>
        </form>
      </div>

      <div class="card" style="padding:22px">
        <div class="section-title">Branding & Links</div>
        <form onsubmit="saveBranding(event)">
          <div class="form-group">
            <label class="form-label">Site Name</label>
            <input type="text" name="site_name" id="s-sname" class="form-input">
          </div>
          <div class="form-group">
            <label class="form-label">Logo / Favicon URL</label>
            <input type="text" name="site_logo" id="s-slogo" class="form-input" placeholder="https://example.com/logo.png" oninput="previewImg(this.value,'logo-prev')">
            <div class="link-preview" id="logo-prev" style="display:none">
              <img id="logo-prev-img" src="" alt=""><span>Live preview — appears in browser tab</span>
            </div>
          </div>

          <div class="section-title" style="font-size:13px;margin-top:16px">🔗 Top Header Links</div>
          <div class="form-group">
            <label class="form-label">Discord URL</label>
            <input type="text" name="discord_url" id="s-discord" class="form-input" placeholder="https://discord.gg/xxxxx" oninput="previewDiscord(this.value)">
            <div class="link-preview" id="disc-prev" style="display:none">
              <img id="disc-prev-img" src="https://cdn.simpleicons.org/discord/5865F2" alt=""><span id="disc-prev-txt"></span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Website URL</label>
            <input type="text" name="website_url" id="s-web" class="form-input" placeholder="https://yourdomain.com" oninput="previewGeneric(this.value,'web-prev','🌐')">
            <div class="link-preview" id="web-prev" style="display:none">
              <span style="font-size:20px">🌐</span><span id="web-prev-txt"></span>
            </div>
          </div>

          <div class="section-title" style="font-size:13px;margin-top:16px">📄 Footer Legal Links</div>
          <div class="form-group">
            <label class="form-label">Terms & Conditions URL</label>
            <input type="text" name="terms_url" id="s-terms" class="form-input" placeholder="https://yourdomain.com/terms" oninput="previewGeneric(this.value,'terms-prev','📋')">
            <div class="link-preview" id="terms-prev" style="display:none">
              <span style="font-size:20px">📋</span><span id="terms-prev-txt"></span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Privacy Policy URL</label>
            <input type="text" name="privacy_url" id="s-privacy" class="form-input" placeholder="https://yourdomain.com/privacy" oninput="previewGeneric(this.value,'priv-prev','🔒')">
            <div class="link-preview" id="priv-prev" style="display:none">
              <span style="font-size:20px">🔒</span><span id="priv-prev-txt"></span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Cookies Policy URL</label>
            <input type="text" name="cookies_url" id="s-cookies" class="form-input" placeholder="https://yourdomain.com/cookies" oninput="previewGeneric(this.value,'cookie-prev','🍪')">
            <div class="link-preview" id="cookie-prev" style="display:none">
              <span style="font-size:20px">🍪</span><span id="cookie-prev-txt"></span>
            </div>
          </div>

          <div class="section-title" style="font-size:13px;margin-top:16px">🎨 Theme</div>
          <div class="form-group">
            <label class="form-label">Default Theme</label>
            <input type="hidden" name="theme_default" id="s-theme" value="green">
            <div class="theme-picker" id="theme-picker">
              <div class="theme-dot" data-t="green" onclick="pickTheme('green')" title="Green"></div>
              <div class="theme-dot" data-t="blue" onclick="pickTheme('blue')" title="Blue"></div>
              <div class="theme-dot" data-t="purple" onclick="pickTheme('purple')" title="Purple"></div>
              <div class="theme-dot" data-t="orange" onclick="pickTheme('orange')" title="Orange"></div>
              <div class="theme-dot" data-t="red" onclick="pickTheme('red')" title="Red"></div>
              <div class="theme-dot" data-t="dark" onclick="pickTheme('dark')" title="Black"></div>
              <div class="theme-dot" data-t="light" onclick="pickTheme('light')" title="White"></div>
            </div>
          </div>

          <button type="submit" class="btn btn-secondary btn-full" style="margin-top:8px">Save Branding & Links</button>
        </form>
      </div>
    </div>
  </div>

  <div id="apanel-nodes" class="hidden">
    <div style="display:flex;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
      <div class="section-title" style="border:none;margin:0;padding:0">Nodes & Game Types</div>
      <button class="btn btn-secondary" onclick="syncPtero()">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 8H12m0 0V5"/></svg>
        Sync with Panel
      </button>
    </div>
    <div class="admin-grid">
      <div class="card" style="padding:20px"><div class="section-title">Nodes</div><div id="nodes-list"><div style="color:var(--text-3);font-size:13px">Click Sync to import.</div></div></div>
      <div class="card" style="padding:20px"><div class="section-title">Game Types</div><div id="eggs-list"><div style="color:var(--text-3);font-size:13px">Click Sync to import.</div></div></div>
    </div>
  </div>

  <div id="apanel-users" class="hidden">
    <div class="card" style="padding:22px">
      <div class="section-title">User Management</div>
      <div class="toolbar">
        <input type="text" class="search-input" id="user-search" placeholder="Search by username or email…" oninput="loadUsers(1)">
        <span class="total-label" id="users-total"></span>
      </div>
      <div class="table-wrap">
        <table><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th>Panel ID</th><th>Actions</th></tr></thead>
        <tbody id="users-tbody"></tbody></table>
      </div>
      <div class="pagination" id="users-pagination" style="margin-top:16px"></div>
    </div>
  </div>

  <div id="apanel-allservers" class="hidden">
    <div class="card" style="padding:22px">
      <div class="section-title">All Panel Servers</div>
      <div class="table-wrap" id="allservers-wrap"><div class="loading-state" style="padding:40px"><div class="spinner"></div></div></div>
    </div>
  </div>

  <div id="apanel-freeservers" class="hidden">
    <div class="card" style="padding:22px">
      <div class="section-title">Free Servers (Dashboard)</div>
      <div class="table-wrap" id="freeservers-wrap"><div class="loading-state" style="padding:40px"><div class="spinner"></div></div></div>
    </div>
  </div>

  <div id="apanel-logs" class="hidden">
    <div class="card" style="padding:22px">
      <div class="section-title">Activity Logs</div>
      <div class="toolbar">
        <input type="text" class="search-input" id="log-filter" placeholder="Filter logs…" oninput="loadLogs(1)">
        <span class="total-label" id="logs-total"></span>
      </div>
      <div style="border:1px solid var(--border);border-radius:var(--radius);overflow:hidden">
        <div class="log-header">
          <span style="min-width:120px">Action</span>
          <span style="min-width:100px">User</span>
          <span style="flex:1">Details</span>
          <span style="min-width:110px">IP</span>
          <span>Time</span>
        </div>
        <div id="logs-body"></div>
      </div>
      <div class="pagination" id="logs-pagination" style="margin-top:16px"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>
</main>

<footer>
  <div class="footer-inner">
    <div>© <?= date('Y') ?> <?= htmlspecialchars($site_name) ?> — All rights reserved</div>
    <div class="footer-links">
      <?php if ($terms_url): ?><a class="footer-link" href="<?= htmlspecialchars($terms_url) ?>" target="_blank">Terms & Conditions</a><?php endif; ?>
      <?php if ($privacy_url): ?><a class="footer-link" href="<?= htmlspecialchars($privacy_url) ?>" target="_blank">Privacy Policy</a><?php endif; ?>
      <?php if ($cookies_url): ?><a class="footer-link" href="<?= htmlspecialchars($cookies_url) ?>" target="_blank">Cookies Policy</a><?php endif; ?>
    </div>
  </div>
</footer>

<?php if ($logged_in): ?>
<div class="modal-overlay" id="modal-create">
  <div class="modal" style="max-width:560px">
    <div class="modal-stripe"></div>
    <div class="modal-header">
      <span class="modal-title">🎁 Deploy Free Server</span>
      <button class="btn-icon" onclick="closeModal('modal-create')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <form onsubmit="doCreateServer(event)">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Server Name</label>
          <input type="text" name="name" class="form-input" placeholder="My Minecraft Server" required maxlength="60">
        </div>
        <div class="spec-hero">
          <div class="spec-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
            Included Free Plan
          </div>
          <div class="spec-grid" id="spec-grid"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group"><label class="form-label">Location</label><select name="node_id" id="create-node" class="form-input" required></select></div>
          <div class="form-group"><label class="form-label">Game Type</label><select name="egg_id" id="create-egg" class="form-input" required></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create')" style="flex:1">Cancel</button>
        <button type="submit" class="btn btn-primary" style="flex:2">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          Deploy Server
        </button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="modal-settings">
  <div class="modal" style="max-width:560px">
    <div class="modal-stripe"></div>
    <div class="modal-header">
      <span class="modal-title">⚙️ Account Settings</span>
      <button class="btn-icon" onclick="closeModal('modal-settings')">
        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="section-title" style="font-size:13px">Change Password</div>
      <form onsubmit="doChangePassword(event)">
        <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="old_password" class="form-input" required></div>
        <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-input" required minlength="8"></div>
        <div class="form-group"><label class="form-label">Confirm Password</label><input type="password" name="confirm_password" class="form-input" required minlength="8"></div>
        <button type="submit" class="btn btn-primary btn-full">Update Password</button>
      </form>
      <div class="section-title" style="font-size:13px;margin-top:24px">Two-Factor Authentication</div>
      <div id="twofa-section"></div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modal-confirm">
  <div class="modal" style="max-width:420px">
    <div class="modal-stripe" style="background:linear-gradient(90deg,#f87171,#ef4444)"></div>
    <div class="modal-body" style="padding-top:22px">
      <div style="width:52px;height:52px;border-radius:14px;background:rgba(248,113,113,0.12);color:var(--danger);display:flex;align-items:center;justify-content:center;margin-bottom:14px">
        <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
      </div>
      <div style="font-size:17px;font-weight:700;margin-bottom:6px">Confirm Action</div>
      <div id="confirm-msg" style="font-size:13.5px;color:var(--text-2);line-height:1.5"></div>
      <div id="confirm-extra" style="margin-top:14px"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('modal-confirm')" style="flex:1">Cancel</button>
      <button class="btn btn-danger" id="confirm-ok" style="flex:1">Confirm</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast-container" id="toasts"></div>

<script>
const IS_ADMIN = <?= json_encode($is_admin) ?>;
const LOGGED_IN = <?= json_encode($logged_in) ?>;
const DISCORD = <?= json_encode($discord) ?>;
const PTERO_URL = <?= json_encode($ptero_url) ?>;

// ═══ UTILS ═══
function escHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmtLimit(val, unit = 'MB') {
  if (val === 0 || val === '0' || val === null || val === undefined) return '∞';
  if (unit === 'MB') {
    if (val >= 1024) { const gb = val / 1024; return (gb % 1 === 0 ? gb.toFixed(0) : gb.toFixed(1)) + ' GB'; }
    return val + ' MB';
  }
  if (unit === '%') return val + '%';
  return val + ' ' + unit;
}
function timeUntil(dateStr) {
  if (!dateStr) return null;
  const target = new Date(dateStr.replace(' ', 'T') + (dateStr.includes('Z') ? '' : 'Z'));
  const diff = target - new Date();
  if (diff <= 0) return { expired: true, text: 'Expired' };
  const days = Math.floor(diff / 86400000);
  const hours = Math.floor((diff % 86400000) / 3600000);
  if (days > 0) return { days, hours, text: days + 'd ' + hours + 'h', expired: false };
  return { hours, text: hours + 'h', expired: false };
}
function toast(msg, type = 'info') {
  const icons = {
    success: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    error: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    info: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    warning: '<svg class="toast-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>',
  };
  const el = document.createElement('div');
  el.className = 'toast toast-' + type;
  el.innerHTML = (icons[type] || icons.info) + '<span class="toast-msg">' + escHtml(msg) + '</span><button class="toast-close" onclick="this.parentElement.remove()">×</button>';
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(8px)'; el.style.transition = 'all .3s'; setTimeout(() => el.remove(), 300); }, 4500);
}
function confirmBox(msg, cb, extraHtml) {
  document.getElementById('confirm-msg').textContent = msg;
  const extra = document.getElementById('confirm-extra');
  extra.innerHTML = extraHtml || '';
  openModal('modal-confirm');
  const btn = document.getElementById('confirm-ok');
  const fresh = btn.cloneNode(true);
  btn.parentNode.replaceChild(fresh, btn);
  fresh.addEventListener('click', () => { closeModal('modal-confirm'); cb(extra); });
}
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => { m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); }); });

async function api(action, method = 'GET', body = null) {
  const opts = { method };
  if (body) opts.body = body instanceof FormData ? body : new URLSearchParams(body);
  const res = await fetch('index.php?action=' + action, opts);
  if (res.status === 401 && action !== 'login' && action !== 'me') { location.reload(); return { success: false }; }
  return res.json();
}

// ═══ AUTH ═══
function authTab(type) {
  ['login','register','2fa'].forEach(t => {
    const b = document.getElementById('atab-' + t);
    const f = document.getElementById('form-' + t);
    if (b) b.classList.toggle('active', t === type);
    if (f) f.classList.toggle('hidden', t !== type);
  });
}
async function doAuth(e, type) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const data = await api(type, 'POST', fd);
    if (!data.success) { toast(data.message, 'error'); return; }
    if (data.needs_2fa) { authTab('2fa'); return; }
    toast(data.message, 'success');
    if (type === 'login') setTimeout(() => location.reload(), 800);
    else { authTab('login'); e.target.reset(); }
  } catch { toast('Connection error.', 'error'); }
}
async function doVerify2fa(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const data = await api('verify_2fa', 'POST', fd);
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 700);
  } catch { toast('Connection error.', 'error'); }
}
async function doLogout() { await fetch('index.php?action=logout'); location.reload(); }

// ═══ DASHBOARD ═══
let currentTab = null;

function switchTab(tab) {
  if (!LOGGED_IN) return;
  currentTab = tab;
  ['dashboard','admin'].forEach(t => {
    const v = document.getElementById('view-' + t);
    const b = document.getElementById('tab-' + t);
    if (v) v.classList.toggle('hidden', t !== tab);
    if (b) b.classList.toggle('active', t === tab);
  });
  if (tab === 'dashboard') { loadServers(); checkPteroWarning(); }
  if (tab === 'admin') { loadAdminSettings(); adminTab('settings'); }
}
function checkPteroWarning() {
  const w = document.getElementById('ptero-warning');
  if (w && IS_ADMIN && !PTERO_URL) w.style.display = 'flex';
  else if (w) w.style.display = 'none';
}

async function loadServers() {
  const grid = document.getElementById('servers-grid');
  grid.innerHTML = '<div class="loading-state"><div class="spinner"></div><span>Loading your servers…</span></div>';
  try {
    const data = await api('get_servers');
    if (!data.success) {
      grid.innerHTML = '<div class="empty-state"><div class="empty-title">Error</div><div class="empty-desc">' + escHtml(data.message || 'Could not fetch servers.') + '</div></div>';
      return;
    }
    if (data.quota) renderQuotaBar(data.quota);

    if (!data.servers.length) {
      grid.innerHTML = `
        <div class="empty-state">
          <div class="empty-icon"><svg width="32" height="32" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12H3l9-9 9 9h-2M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/></svg></div>
          <h3 class="empty-title">No servers yet</h3>
          <p class="empty-desc">Deploy your first free game server — it only takes a few seconds.</p>
          <button class="btn btn-primary" onclick="openCreateModal()">Deploy Free Server</button>
        </div>`;
      return;
    }
    grid.innerHTML = '';
    data.servers.forEach((s, i) => {
      const card = document.createElement('div');
      card.className = 'server-card' + (s.is_free ? ' free-card' : '');
      card.style.animationDelay = (i * 0.04) + 's';
      card.innerHTML = renderServerCard(s);
      grid.appendChild(card);
    });
  } catch { grid.innerHTML = '<div class="empty-state"><div class="empty-title">Connection Error</div><div class="empty-desc">Could not reach the API.</div></div>'; }
}

function renderQuotaBar(q) {
  const bar = document.getElementById('user-quota');
  if (!bar) return;
  bar.style.display = 'flex';
  const mu = q.per_user.used, mm = q.per_user.max;
  const tu = q.total.used, tm = q.total.max;
  const mineNum = document.getElementById('quota-bar-mine-num');
  const totalNum = document.getElementById('quota-bar-total-num');
  const mineFill = document.getElementById('quota-bar-mine');
  const totalFill = document.getElementById('quota-bar-total');
  const sub = document.getElementById('user-quota-sub');
  const minePct = mm > 0 ? Math.min(100, (mu / mm) * 100) : 0;
  const totalPct = tm > 0 ? Math.min(100, (tu / tm) * 100) : 0;
  mineNum.textContent = mu + '/' + mm;
  totalNum.textContent = tu + '/' + (tm > 0 ? tm : '∞');
  mineFill.style.width = minePct + '%';
  totalFill.style.width = totalPct + '%';
  mineFill.className = 'quota-bar-fill ' + (minePct >= 100 ? 'full' : minePct >= 80 ? 'warn' : '');
  totalFill.className = 'quota-bar-fill ' + (totalPct >= 100 ? 'full' : totalPct >= 80 ? 'warn' : '');
  if (mu >= mm) sub.textContent = 'You have reached your personal limit. Delete a server to free a slot.';
  else if (tm > 0 && tu >= tm) sub.textContent = 'The host has reached its global limit. Please try again later.';
  else sub.textContent = 'You can create ' + (mm - mu) + ' more server' + (mm - mu === 1 ? '' : 's') + '.';
  const createBtn = document.querySelector('.page-header .btn-primary');
  if (createBtn) {
    const blocked = mu >= mm || (tm > 0 && tu >= tm);
    createBtn.disabled = blocked;
    createBtn.style.opacity = blocked ? '.5' : '';
    createBtn.title = blocked ? 'You have reached the limit' : '';
  }
}

function renderServerCard(s) {
  const free = s.free;
  const expired = free && free.status === 'expired';
  let statusClass = 'status-active', statusLabel = 'Active';
  if (s.suspended || expired) { statusClass = 'status-suspended'; statusLabel = s.suspended ? 'Suspended' : 'Expired'; }

  let expiryHtml = '';
  if (s.is_free && free && free.expires_at) {
    const t = timeUntil(free.expires_at);
    let cls = 'expiry-bar';
    if (t.expired) cls += ' danger';
    else if (t.days !== undefined && t.days < 2) cls += ' warn';
    expiryHtml = '<div class="' + cls + '"><div class="exp-title">⏳ Expires in</div><div class="exp-val">' + t.text + '</div></div>';
  } else if (s.is_free) {
    expiryHtml = '<div class="expiry-bar"><div class="exp-title">✓ No expiry</div><div class="exp-val">Renewal disabled</div></div>';
  }

  const panelLink = PTERO_URL ? PTERO_URL + '/server/' + s.identifier : '#';
  const panelAttrs = !PTERO_URL ? 'onclick="toast(\'Panel URL is not configured.\',\'warning\');return false;"' : '';
  let actionHtml = '';
  if (s.is_free) {
    actionHtml = `<div class="server-actions">
      ${free && free.expires_at ? `<button class="btn btn-success btn-sm" style="flex:1" onclick="renewServer(${s.id},'${escHtml(s.name)}')">♻ Renew</button>` : ''}
      <a href="${panelLink}" target="_blank" class="btn btn-primary btn-sm" style="flex:1" ${panelAttrs}>Open Console</a>
      <button class="btn btn-danger btn-sm" onclick="deleteServer(${s.id},'${escHtml(s.name)}',true)">🗑</button>
    </div>`;
  } else {
    actionHtml = `<div class="server-actions">
      <a href="${panelLink}" target="_blank" class="btn btn-primary btn-sm" style="flex:1" ${panelAttrs}>Open Console</a>
      <button class="btn btn-secondary btn-sm" onclick="showTicketMsg()">🗑</button>
    </div>`;
  }

  return `
    ${s.is_free ? '<span class="free-tag">🎁 Free</span>' : ''}
    <span class="server-status ${statusClass}"><span class="status-dot"></span>${statusLabel}</span>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px">
      <div style="min-width:0;flex:1">
        <div class="server-name">${escHtml(s.name)}</div>
        <div class="server-id">${s.identifier}</div>
      </div>
    </div>
    <div class="server-stats">
      <div class="stat-item"><div class="stat-label">RAM</div><div class="stat-value">${fmtLimit(s.limits.memory)}</div></div>
      <div class="stat-item"><div class="stat-label">Disk</div><div class="stat-value">${fmtLimit(s.limits.disk)}</div></div>
      <div class="stat-item"><div class="stat-label">CPU</div><div class="stat-value">${fmtLimit(s.limits.cpu, '%')}</div></div>
    </div>
    ${expiryHtml}
    ${actionHtml}`;
}

function showTicketMsg() {
  const msg = DISCORD
    ? 'This server was created directly in the panel and can only be deleted by an administrator. Please open a Discord ticket.'
    : 'This server was created directly in the panel and can only be deleted by an administrator. Please contact support.';
  confirmBox(msg, () => { if (DISCORD) window.open(DISCORD, '_blank'); });
}

async function renewServer(id, name) {
  confirmBox('Renew "' + name + '"? Extra days will be added to its expiry.', async () => {
    try {
      const fd = new FormData(); fd.append('server_id', id);
      const d = await api('renew_server', 'POST', fd);
      toast(d.message, d.success ? 'success' : 'error');
      if (d.success) loadServers();
    } catch { toast('Renew failed.', 'error'); }
  });
}

function deleteServer(id, name, isFree) {
  if (!isFree && !IS_ADMIN) { showTicketMsg(); return; }
  confirmBox('Permanently delete "' + name + '"? This cannot be undone.', async () => {
    try {
      const fd = new FormData(); fd.append('server_id', id);
      const d = await api('delete_server', 'POST', fd);
      if (!d.success && d.ticket) { toast(d.message, 'warning'); if (DISCORD) window.open(DISCORD, '_blank'); return; }
      toast(d.message, d.success ? 'success' : 'error');
      if (d.success) loadServers();
    } catch { toast('Delete failed.', 'error'); }
  });
}

async function openCreateModal() {
  const nodeEl = document.getElementById('create-node');
  const eggEl = document.getElementById('create-egg');
  const specEl = document.getElementById('spec-grid');
  nodeEl.innerHTML = eggEl.innerHTML = '<option>Loading…</option>';
  specEl.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--text-3);font-size:12px">Loading plan…</div>';
  openModal('modal-create');
  try {
    const data = await api('get_active_nodes_eggs');
    nodeEl.innerHTML = data.nodes.length ? data.nodes.map(n => `<option value="${n.node_id}">${escHtml(n.name)}</option>`).join('') : '<option value="">No nodes available</option>';
    eggEl.innerHTML = data.eggs.length ? data.eggs.map(e => `<option value="${e.egg_id}">${escHtml(e.name)}</option>`).join('') : '<option value="">No game types available</option>';
    const L = data.limits;
    specEl.innerHTML = `
      <div class="spec-box"><div class="spec-ic">🧠</div><div class="spec-l">RAM</div><div class="spec-v">${fmtLimit(+L.ram)}</div></div>
      <div class="spec-box"><div class="spec-ic">💾</div><div class="spec-l">Disk</div><div class="spec-v">${fmtLimit(+L.disk)}</div></div>
      <div class="spec-box"><div class="spec-ic">⚡</div><div class="spec-l">CPU</div><div class="spec-v">${fmtLimit(+L.cpu,'%')}</div></div>
      <div class="spec-box"><div class="spec-ic">🌐</div><div class="spec-l">Ports</div><div class="spec-v">${fmtLimit(+L.allocations)}</div></div>
      <div class="spec-box"><div class="spec-ic">🗄️</div><div class="spec-l">Databases</div><div class="spec-v">${fmtLimit(+L.databases)}</div></div>
      <div class="spec-box"><div class="spec-ic">📦</div><div class="spec-l">Backups</div><div class="spec-v">${fmtLimit(+L.backups)}</div></div>`;
  } catch { toast('Failed to load plan.', 'error'); }
}

async function doCreateServer(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  closeModal('modal-create');
  toast('Deploying your server… please wait.', 'info');
  try {
    const data = await api('create_server', 'POST', fd);
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(loadServers, 2500);
  } catch { toast('Deploy request failed.', 'error'); }
}

// ═══ SETTINGS MODAL ═══
async function openSettings() {
  openModal('modal-settings');
  await renderTwoFaSection();
}
async function renderTwoFaSection() {
  const el = document.getElementById('twofa-section');
  el.innerHTML = '<div class="spinner" style="width:24px;height:24px"></div>';
  try {
    const data = await api('me');
    if (!data.success) return;
    const enabled = data.user.twofa_enabled == 1;
    el.innerHTML = enabled ? `
      <div class="toggle-row"><div><div class="toggle-label">✅ 2FA Enabled</div><div class="toggle-sublabel">Your account is protected</div></div></div>
      <button class="btn btn-danger btn-full" onclick="disable2fa()">Disable 2FA</button>
    ` : `
      <div class="toggle-row"><div><div class="toggle-label">❌ 2FA Disabled</div><div class="toggle-sublabel">Add an extra layer of security</div></div></div>
      <button class="btn btn-success btn-full" onclick="setup2fa()">Enable 2FA</button>
    `;
  } catch { el.innerHTML = ''; }
}
async function setup2fa() {
  try {
    const data = await api('2fa_setup');
    if (!data.success) { toast(data.message, 'error'); return; }
    const qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(data.uri);
    const el = document.getElementById('twofa-section');
    el.innerHTML = `
      <div style="text-align:center;padding:14px;background:var(--surface-2);border-radius:var(--radius);border:1px solid var(--border);margin-bottom:14px">
        <img src="${qrUrl}" alt="QR" style="background:#fff;border-radius:8px;padding:6px;width:180px;height:180px">
        <div style="font-size:11px;color:var(--text-3);margin-top:8px">Scan with Google Authenticator / Authy / 1Password</div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-2);margin-top:8px;word-break:break-all;padding:8px;background:var(--bg-2);border-radius:8px">${data.secret}</div>
      </div>
      <form onsubmit="confirm2fa(event)">
        <div class="form-group"><label class="form-label">Enter the 6-digit code</label>
          <input type="text" name="code" class="form-input" placeholder="000000" required maxlength="6" style="text-align:center;font-family:'JetBrains Mono',monospace;font-size:18px;letter-spacing:.3em">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Verify & Enable</button>
      </form>`;
  } catch { toast('Setup failed.', 'error'); }
}
async function confirm2fa(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const d = await api('2fa_enable', 'POST', fd);
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) renderTwoFaSection();
  } catch { toast('Failed to enable 2FA.', 'error'); }
}
async function disable2fa() {
  const pw = prompt('Enter your password to disable 2FA:');
  if (!pw) return;
  const fd = new FormData(); fd.append('password', pw);
  try {
    const d = await api('2fa_disable', 'POST', fd);
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) renderTwoFaSection();
  } catch { toast('Failed to disable 2FA.', 'error'); }
}
async function doChangePassword(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const d = await api('change_password', 'POST', fd);
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) e.target.reset();
  } catch { toast('Password change failed.', 'error'); }
}

// ═══ ADMIN ═══
function adminTab(name) {
  ['settings','nodes','users','allservers','freeservers','logs'].forEach(t => {
    const p = document.getElementById('apanel-' + t);
    const b = document.getElementById('atab-' + t);
    if (p) p.classList.toggle('hidden', t !== name);
    if (b) b.classList.toggle('active', t === name);
  });
  if (name === 'nodes') loadNodesEggs();
  if (name === 'users') loadUsers(1);
  if (name === 'allservers') loadAllServers();
  if (name === 'freeservers') loadFreeServers();
  if (name === 'logs') loadLogs(1);
}

async function loadAdminSettings() {
  try {
    const data = await api('admin_get_settings');
    if (!data.success) return;
    const s = data.settings;
    const f = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    f('s-url', s.ptero_url); f('s-akey', s.ptero_admin_key); f('s-ckey', s.ptero_client_key);
    f('s-ram', s.default_ram); f('s-disk', s.default_disk); f('s-cpu', s.default_cpu);
    f('s-alloc', s.default_allocations); f('s-db', s.default_databases); f('s-backups', s.default_backups);
    f('s-maxuser', s.max_free_per_user); f('s-maxtotal', s.max_free_total);
    f('s-desc', s.server_description);
    f('s-renew-en', s.renew_enabled);
    f('s-renew-days', s.renew_days); f('s-renew-bonus', s.renew_bonus_days);
    f('s-sname', s.site_name); f('s-slogo', s.site_logo);
    f('s-discord', s.discord_url); f('s-web', s.website_url);
    f('s-terms', s.terms_url); f('s-privacy', s.privacy_url); f('s-cookies', s.cookies_url);
    f('s-theme', s.theme_default);
    pickTheme(s.theme_default || 'green', true);
    if (s.site_logo) previewImg(s.site_logo, 'logo-prev');
    if (s.discord_url) previewDiscord(s.discord_url);
    if (s.website_url) previewGeneric(s.website_url, 'web-prev', '🌐');
    if (s.terms_url) previewGeneric(s.terms_url, 'terms-prev', '📋');
    if (s.privacy_url) previewGeneric(s.privacy_url, 'priv-prev', '🔒');
    if (s.cookies_url) previewGeneric(s.cookies_url, 'cookie-prev', '🍪');
    loadAdminQuotaUsage();
  } catch { toast('Failed to load settings.', 'error'); }
}
async function loadAdminQuotaUsage() {
  try {
    const d = await api('get_servers');
    if (!d.success || !d.quota) return;
    const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
    set('usage-mine', d.quota.per_user.used);
    set('usage-mine-max', d.quota.per_user.max);
    set('usage-total', d.quota.total.used);
    set('usage-total-max', d.quota.total.max > 0 ? d.quota.total.max : '∞');
  } catch {}
}
function pickTheme(t, silent) {
  const h = document.getElementById('s-theme');
  if (h) h.value = t;
  document.querySelectorAll('.theme-dot').forEach(d => d.classList.toggle('active', d.dataset.t === t));
  document.documentElement.setAttribute('data-theme', t);
  if (!silent) toast('Theme: ' + t, 'info');
}
function previewImg(url, previewId) {
  const p = document.getElementById(previewId);
  if (!p) return;
  if (!url) { p.style.display = 'none'; return; }
  p.style.display = 'flex';
  const img = p.querySelector('img');
  if (img) img.src = url;
}
function previewDiscord(url) {
  const p = document.getElementById('disc-prev');
  if (!p) return;
  if (!url) { p.style.display = 'none'; return; }
  p.style.display = 'flex';
  document.getElementById('disc-prev-txt').textContent = url;
}
function previewGeneric(url, previewId, icon) {
  const p = document.getElementById(previewId);
  if (!p) return;
  if (!url) { p.style.display = 'none'; return; }
  p.style.display = 'flex';
  const txt = p.querySelector('span:last-child');
  if (txt) txt.textContent = url;
}

async function saveSettings(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const data = await api('admin_save_settings', 'POST', fd);
    toast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 900);
  } catch { toast('Save failed.', 'error'); }
}
async function saveBranding(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  try {
    const data = await api('admin_save_settings', 'POST', fd);
    toast(data.message || 'Branding saved.', data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 900);
  } catch { toast('Save failed.', 'error'); }
}
async function syncPtero() {
  toast('Syncing with panel…', 'info');
  try {
    const d = await api('admin_sync_ptero');
    toast(d.message, d.success ? 'success' : 'error');
    if (d.success) loadNodesEggs();
  } catch { toast('Sync failed.', 'error'); }
}
async function loadNodesEggs() {
  try {
    const d = await api('admin_get_nodes_eggs');
    const nl = document.getElementById('nodes-list');
    const el = document.getElementById('eggs-list');
    nl.innerHTML = d.nodes.length ? d.nodes.map(n => toggleRow(n.node_id, n.name, n.is_active, 'node')).join('') : '<div style="color:var(--text-3);font-size:13px">No nodes. Click Sync to import.</div>';
    el.innerHTML = d.eggs.length ? d.eggs.map(e => toggleRow(e.egg_id, e.name, e.is_active, 'egg')).join('') : '<div style="color:var(--text-3);font-size:13px">No game types. Click Sync to import.</div>';
    document.querySelectorAll('[data-toggle]').forEach(inp => {
      inp.addEventListener('change', function () {
        const type = this.dataset.toggle, id = this.dataset.id;
        const fd = new FormData();
        fd.append(type === 'node' ? 'node_id' : 'egg_id', id);
        fd.append('status', this.checked ? 1 : 0);
        api('admin_toggle_' + type, 'POST', fd).then(d => { if (d.success) toast(d.message || 'Updated.', 'success'); });
      });
    });
  } catch { toast('Failed to load nodes and games.', 'error'); }
}
function toggleRow(id, name, active, type) {
  return `<div class="toggle-row">
    <div><div class="toggle-label">${escHtml(name)}</div><div class="toggle-sublabel">${type === 'node' ? 'Node' : 'Game type'} #${id}</div></div>
    <label class="toggle"><input type="checkbox" ${active == 1 ? 'checked' : ''} data-toggle="${type}" data-id="${id}"><span class="toggle-track"></span></label>
  </div>`;
}

// ═══ USERS ═══
let userPage = 1;
let usersCache = [];

async function loadUsers(page) {
  userPage = page || 1;
  const search = document.getElementById('user-search')?.value || '';
  try {
    const d = await api('admin_get_users&search=' + encodeURIComponent(search) + '&page=' + userPage);
    usersCache = d.users || [];
    document.getElementById('users-total').textContent = d.total + ' user' + (d.total === 1 ? '' : 's');
    const tbody = document.getElementById('users-tbody');
    tbody.innerHTML = usersCache.map(u => `
      <tr>
        <td><strong>${escHtml(u.username)}</strong></td>
        <td style="color:var(--text-2);font-size:12px">${escHtml(u.email)}</td>
        <td><span class="${u.is_admin == 1 ? 'badge-admin' : 'badge-user'}">${u.is_admin == 1 ? 'Admin' : 'User'}</span></td>
        <td>${u.is_banned == 1 ? '<span class="badge-banned">Banned</span>' : '<span style="color:var(--success);font-size:11px">Active</span>'}</td>
        <td style="font-size:11px;color:var(--text-3)">${u.twofa_enabled == 1 ? '✅' : '—'}</td>
        <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-3)">${u.ptero_user_id || '—'}</td>
        <td>
          <div style="display:flex;gap:4px;flex-wrap:wrap">
            ${u.id == 1
              ? '<span style="font-size:11px;color:var(--text-3)">Root — protected</span>'
              : `
                <button class="btn btn-sm btn-warning" data-act="toggle_admin" data-uid="${u.id}" data-uname="${escHtml(u.username)}">Toggle Admin</button>
                <button class="btn btn-sm btn-secondary" data-act="reset_pass" data-uid="${u.id}" data-uname="${escHtml(u.username)}">Reset PW</button>
                ${u.is_banned == 1
                  ? `<button class="btn btn-sm btn-success" data-act="unban" data-uid="${u.id}" data-uname="${escHtml(u.username)}">Unban</button>`
                  : `<button class="btn btn-sm btn-danger" data-act="ban" data-uid="${u.id}" data-uname="${escHtml(u.username)}">Ban</button>`}
                <button class="btn btn-sm btn-danger" data-act="delete" data-uid="${u.id}" data-uname="${escHtml(u.username)}">Delete</button>
              `}
          </div>
        </td>
      </tr>`).join('');

    // Attach listeners
    tbody.querySelectorAll('button[data-act]').forEach(btn => {
      btn.addEventListener('click', () => handleUserAction(btn.dataset.act, +btn.dataset.uid, btn.dataset.uname));
    });

    const pgEl = document.getElementById('users-pagination');
    pgEl.innerHTML = '';
    for (let i = 1; i <= d.pages; i++) {
      const b = document.createElement('button');
      b.className = 'page-btn' + (i === userPage ? ' active' : '');
      b.textContent = i;
      b.addEventListener('click', () => loadUsers(i));
      pgEl.appendChild(b);
    }
  } catch { toast('Failed to load users.', 'error'); }
}

async function handleUserAction(task, uid, uname) {
  switch (task) {
    case 'toggle_admin':
      confirmBox(`Toggle admin role for "${uname}"?`, async () => {
        const fd = new FormData(); fd.append('user_id', uid); fd.append('task', 'toggle_admin');
        const d = await api('admin_user_action', 'POST', fd);
        toast(d.message || (d.success ? 'Role updated.' : 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) loadUsers(userPage);
      });
      break;

    case 'unban':
      confirmBox(`Unban "${uname}"? They will be able to log in again.`, async () => {
        const fd = new FormData(); fd.append('user_id', uid); fd.append('task', 'unban');
        const d = await api('admin_user_action', 'POST', fd);
        toast(d.message || (d.success ? 'User unbanned.' : 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) loadUsers(userPage);
      });
      break;

    case 'ban':
      confirmBox(`Ban "${uname}"? They will not be able to log in.`, async (extra) => {
        const reason = (extra.querySelector('input')?.value || 'No reason provided.').trim();
        const fd = new FormData(); fd.append('user_id', uid); fd.append('task', 'ban'); fd.append('reason', reason);
        const d = await api('admin_user_action', 'POST', fd);
        toast(d.message || (d.success ? 'User banned.' : 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) loadUsers(userPage);
      }, '<div class="form-group"><label class="form-label">Ban Reason</label><input type="text" class="form-input" placeholder="Enter reason..." required></div>');
      setTimeout(() => document.querySelector('#confirm-extra input')?.focus(), 150);
      break;

    case 'reset_pass':
      confirmBox(`Set a new password for "${uname}":`, async (extra) => {
        const pw = (extra.querySelector('input')?.value || '').trim();
        if (pw.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }
        const fd = new FormData(); fd.append('user_id', uid); fd.append('task', 'reset_pass'); fd.append('password', pw);
        const d = await api('admin_user_action', 'POST', fd);
        toast(d.message || (d.success ? 'Password reset.' : 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) loadUsers(userPage);
      }, '<div class="form-group"><label class="form-label">New Password</label><input type="text" class="form-input" placeholder="Minimum 8 characters" required minlength="8"></div>');
      setTimeout(() => document.querySelector('#confirm-extra input')?.focus(), 150);
      break;

    case 'delete':
      confirmBox(`Permanently delete user "${uname}"? This cannot be undone.`, async () => {
        const fd = new FormData(); fd.append('user_id', uid); fd.append('task', 'delete');
        const d = await api('admin_user_action', 'POST', fd);
        toast(d.message || (d.success ? 'User deleted.' : 'Failed.'), d.success ? 'success' : 'error');
        if (d.success) loadUsers(userPage);
      });
      break;
  }
}

// ═══ ALL SERVERS ═══
async function loadAllServers() {
  const wrap = document.getElementById('allservers-wrap');
  wrap.innerHTML = '<div class="loading-state" style="padding:40px"><div class="spinner"></div></div>';
  try {
    const d = await api('admin_all_servers');
    if (!d.success) { wrap.innerHTML = '<div style="padding:20px;color:var(--danger)">' + escHtml(d.message) + '</div>'; return; }
    if (!d.servers.length) { wrap.innerHTML = '<div style="padding:20px;color:var(--text-3)">No servers found.</div>'; return; }
    wrap.innerHTML = `<table>
      <thead><tr><th>ID</th><th>Name</th><th>Node</th><th>User</th><th>RAM</th><th>CPU</th><th>Status</th></tr></thead>
      <tbody>${d.servers.map(s => `<tr>
        <td style="font-family:'JetBrains Mono',monospace;font-size:11px">${s.identifier}</td>
        <td><strong>${escHtml(s.name)}</strong></td>
        <td style="color:var(--text-3)">${s.node || '—'}</td>
        <td style="color:var(--text-3)">${s.user}</td>
        <td>${fmtLimit(s.limits.memory)}</td>
        <td>${fmtLimit(s.limits.cpu, '%')}</td>
        <td>${s.suspended ? '<span class="badge-banned">Suspended</span>' : '<span style="color:var(--success);font-size:11px">Active</span>'}</td>
      </tr>`).join('')}</tbody>
    </table>`;
  } catch { wrap.innerHTML = '<div style="padding:20px;color:var(--danger)">Failed to load servers.</div>'; }
}

// ═══ FREE SERVERS ═══
async function loadFreeServers() {
  const wrap = document.getElementById('freeservers-wrap');
  wrap.innerHTML = '<div class="loading-state" style="padding:40px"><div class="spinner"></div></div>';
  try {
    const d = await api('admin_get_free_servers');
    if (!d.success) { wrap.innerHTML = '<div style="padding:20px;color:var(--danger)">' + escHtml(d.message) + '</div>'; return; }
    if (!d.servers.length) { wrap.innerHTML = '<div style="padding:20px;color:var(--text-3)">No free servers created yet.</div>'; return; }
    wrap.innerHTML = `<table>
      <thead><tr><th>ID</th><th>Owner</th><th>Name</th><th>Status</th><th>Expires In</th><th>Last Renewal</th></tr></thead>
      <tbody>${d.servers.map(fs => {
        const t = fs.expires_at ? timeUntil(fs.expires_at) : null;
        return `<tr>
          <td style="font-family:'JetBrains Mono',monospace;font-size:11px">${escHtml(fs.ptero_identifier)}</td>
          <td><strong>${escHtml(fs.username)}</strong><div style="font-size:11px;color:var(--text-3)">${escHtml(fs.email)}</div></td>
          <td>${escHtml(fs.name)}</td>
          <td>${fs.status === 'expired' ? '<span class="badge-banned">Expired</span>' : '<span style="color:var(--success);font-size:11px">Active</span>'}</td>
          <td>${t ? t.text : '—'}</td>
          <td style="font-size:11px;color:var(--text-3)">${fs.last_renewed_at || '—'}</td>
        </tr>`;
      }).join('')}</tbody>
    </table>`;
  } catch { wrap.innerHTML = '<div style="padding:20px;color:var(--danger)">Failed to load free servers.</div>'; }
}

// ═══ LOGS ═══
let logPage = 1;
async function loadLogs(page) {
  logPage = page || 1;
  const filter = document.getElementById('log-filter')?.value || '';
  const body = document.getElementById('logs-body');
  body.innerHTML = '<div style="padding:20px;color:var(--text-3);text-align:center">Loading…</div>';
  try {
    const d = await api('get_logs&page=' + logPage + '&filter=' + encodeURIComponent(filter));
    document.getElementById('logs-total').textContent = d.total + ' entr' + (d.total === 1 ? 'y' : 'ies');
    body.innerHTML = d.logs.length
      ? d.logs.map(l => `
        <div class="log-entry">
          <span class="log-action">${escHtml(l.action)}</span>
          <span class="log-user">${escHtml(l.username || '—')}</span>
          <span class="log-details">${escHtml(l.details || '—')}</span>
          <span class="log-ip">${escHtml(l.ip || '—')}</span>
          <span class="log-time">${(l.created_at || '').slice(0,16).replace('T',' ')}</span>
        </div>`).join('')
      : '<div style="padding:20px;color:var(--text-3);text-align:center">No logs found.</div>';

    const pgEl = document.getElementById('logs-pagination');
    pgEl.innerHTML = '';
    for (let i = 1; i <= d.pages; i++) {
      const b = document.createElement('button');
      b.className = 'page-btn' + (i === logPage ? ' active' : '');
      b.textContent = i;
      b.addEventListener('click', () => loadLogs(i));
      pgEl.appendChild(b);
    }
  } catch { body.innerHTML = '<div style="padding:20px;color:var(--danger)">Failed to load logs.</div>'; }
}

// ═══ START ═══
if (LOGGED_IN) {
  switchTab('dashboard');
  setInterval(() => {
    if (currentTab === 'dashboard' && !document.hidden) {
      api('get_servers').then(d => { if (d.success && d.quota) renderQuotaBar(d.quota); });
    }
  }, 60000);
}
</script>
</body>
</html>
