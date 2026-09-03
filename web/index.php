<?php
session_start();
if (empty($_SESSION['id'])) { header('Location: login/'); exit; }

$config_path = '../config.json';
$config = json_decode(file_get_contents($config_path), true);
if (!$config) die('Failed to parse config.json');

$message = '';
$message_type = 'success';

function save_config($path, $cfg) {
    file_put_contents($path, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function current_username($config) {
    foreach ($config['users'] as $u) {
        if ($u['id'] == $_SESSION['id']) return $u['name'];
    }
    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_static') {
        $mac = strtolower(trim($_POST['mac'] ?? ''));
        $ip  = trim($_POST['ip'] ?? '');
        foreach ($config['data'] as &$dev) {
            if (strtolower($dev['mac']) === $mac) {
                if ($ip) $dev['static_ip'] = $ip;
                else unset($dev['static_ip']);
                break;
            }
        }
        unset($dev);
        save_config($config_path, $config);
        $message = 'Static IP updated.';
    }

    if ($action === 'add_device') {
        $title = trim($_POST['title'] ?? '');
        $mac   = strtolower(trim($_POST['mac'] ?? ''));
        $ip    = trim($_POST['ip'] ?? '');
        if ($title && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac)) {
            $entry = ['title' => $title, 'mac' => $mac, 'order' => count($config['data']) + 1];
            if ($ip) $entry['static_ip'] = $ip;
            $config['data'][] = $entry;
            save_config($config_path, $config);
            $message = "Device '{$title}' added.";
        } else {
            $message = 'Invalid title or MAC address.';
            $message_type = 'error';
        }
    }

    if ($action === 'delete_device') {
        $mac = strtolower(trim($_POST['mac'] ?? ''));
        $config['data'] = array_values(array_filter($config['data'], fn($d) => strtolower($d['mac']) !== $mac));
        save_config($config_path, $config);
        $message = 'Device removed.';
    }

    if ($action === 'apply_static') {
        $static_file = $config['configuration']['static_lease_file'] ?? '';
        $reload_cmd  = $config['configuration']['reload_cmd'] ?? '';
        if ($static_file) {
            $snippet = build_snippet($config['data']);
            $tmp = tempnam(sys_get_temp_dir(), 'dhcp_');
            file_put_contents($tmp, $snippet);
            exec('sudo cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($static_file) . ' 2>&1', $out1, $rc1);
            unlink($tmp);
            if ($rc1 === 0 && $reload_cmd) {
                exec($reload_cmd . ' 2>&1', $out2, $rc2);
                $message = $rc2 === 0 ? 'Applied and DHCP server reloaded.' : 'Written but reload failed: ' . implode(' ', $out2);
                if ($rc2 !== 0) $message_type = 'error';
            } elseif ($rc1 === 0) {
                $message = 'Static lease file written.';
            } else {
                $message = 'Failed to write file: ' . implode(' ', $out1);
                $message_type = 'error';
            }
        } else {
            $message = 'static_lease_file not configured.';
            $message_type = 'error';
        }
    }

    if ($action === 'change_password') {
        $old  = $_POST['old_pass'] ?? '';
        $new1 = $_POST['new_pass'] ?? '';
        $new2 = $_POST['confirm_pass'] ?? '';
        foreach ($config['users'] as &$user) {
            if ($user['id'] == $_SESSION['id']) {
                $hash = $user['pass'];
                $valid = password_verify($old, $hash) || $hash === $old;
                if (!$valid) {
                    $message = 'Current password is incorrect.'; $message_type = 'error';
                } elseif ($new1 !== $new2 || strlen($new1) < 4) {
                    $message = 'Passwords do not match or are too short (min 4).'; $message_type = 'error';
                } else {
                    $user['pass'] = password_hash($new1, PASSWORD_BCRYPT);
                    save_config($config_path, $config);
                    $message = 'Password updated.';
                }
                break;
            }
        }
        unset($user);
    }

    $config = json_decode(file_get_contents($config_path), true);
}

function build_snippet($data) {
    $out = '';
    foreach ($data as $dev) {
        if (!empty($dev['static_ip'])) {
            $name = preg_replace('/[^a-z0-9_-]/i', '_', $dev['title']);
            $out .= "host {$name} {\n    hardware ethernet {$dev['mac']};\n    fixed-address {$dev['static_ip']};\n}\n\n";
        }
    }
    return $out;
}

// Fetch leases
$leases_by_mac = [];
$binary = $config['configuration']['binary'];
foreach ($config['configuration']['lease_files'] as $lease_file) {
    $cmd = $binary . ' --parsable --lease ' . escapeshellarg($lease_file) . ' 2>/dev/null';
    exec($cmd, $raw_output);
    foreach ($raw_output as $line) {
        $line = trim($line);
        if (!$line) continue;
        $f = explode(' ', $line);
        $mac = ''; $ip = '';
        foreach ($f as $token) {
            if (!$mac && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $token)) $mac = strtolower($token);
            if (!$ip  && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $token)) $ip = $token;
        }
        if ($mac) $leases_by_mac[$mac] = ['mac' => $mac, 'ip' => $ip, 'fields' => $f];
    }
}

$devices = $config['data'] ?? [];
usort($devices, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

$snippet = build_snippet($devices);
$tab = $_GET['tab'] ?? 'leases';
$username = current_username($config);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DHCP Leases</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; }

  /* Nav */
  nav { background: #1a1a2e; color: #fff; display: flex; align-items: center; padding: 0 24px; height: 56px; gap: 16px; }
  nav .brand { font-weight: 700; font-size: 1.1rem; letter-spacing: .5px; margin-right: auto; }
  nav .user { font-size: .85rem; opacity: .7; }
  nav a.logout { color: #ff6b6b; text-decoration: none; font-size: .85rem; font-weight: 600; }
  nav a.logout:hover { color: #ff9999; }

  /* Tabs */
  .tabs { background: #fff; border-bottom: 1px solid #e0e0e0; display: flex; padding: 0 24px; }
  .tabs a { display: block; padding: 14px 20px; text-decoration: none; color: #555; font-size: .9rem; font-weight: 500; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s; }
  .tabs a:hover { color: #1a1a2e; }
  .tabs a.active { color: #1a1a2e; border-bottom-color: #4a90d9; }

  /* Content */
  .content { max-width: 1100px; margin: 0 auto; padding: 28px 20px; }

  .flash { padding: 12px 18px; border-radius: 6px; margin-bottom: 20px; font-size: .9rem; }
  .flash.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
  .flash.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

  h2 { font-size: 1.1rem; font-weight: 600; margin-bottom: 16px; color: #1a1a2e; }

  /* Tables */
  .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); overflow: hidden; margin-bottom: 28px; }
  table { width: 100%; border-collapse: collapse; font-size: .9rem; }
  th { background: #f7f8fa; text-align: left; padding: 11px 16px; font-size: .8rem; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #e8e8e8; }
  td { padding: 11px 16px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fafbfc; }

  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: .75rem; font-weight: 600; }
  .badge.online  { background: #d4edda; color: #155724; }
  .badge.offline { background: #f0f0f0; color: #999; }
  .badge.static  { background: #d1ecf1; color: #0c5460; }

  .mono { font-family: 'SF Mono', 'Fira Code', monospace; font-size: .85rem; }

  /* Forms */
  .form-row { display: flex; gap: 10px; flex-wrap: wrap; padding: 16px; border-top: 1px solid #f0f0f0; align-items: flex-end; }
  .form-group { display: flex; flex-direction: column; gap: 4px; }
  .form-group label { font-size: .78rem; font-weight: 600; color: #666; text-transform: uppercase; }
  input[type=text], input[type=password] { border: 1px solid #ddd; border-radius: 5px; padding: 7px 10px; font-size: .9rem; outline: none; transition: border-color .15s; width: 100%; }
  input[type=text]:focus, input[type=password]:focus { border-color: #4a90d9; }

  .btn { display: inline-block; padding: 7px 16px; border-radius: 5px; font-size: .88rem; font-weight: 600; cursor: pointer; border: none; transition: background .15s; }
  .btn-primary { background: #4a90d9; color: #fff; }
  .btn-primary:hover { background: #357abd; }
  .btn-danger  { background: #e74c3c; color: #fff; }
  .btn-danger:hover  { background: #c0392b; }
  .btn-success { background: #27ae60; color: #fff; }
  .btn-success:hover { background: #219150; }
  .btn-sm { padding: 4px 10px; font-size: .8rem; }

  /* Snippet */
  pre { background: #1e1e2e; color: #cdd6f4; padding: 16px; border-radius: 6px; font-size: .85rem; overflow-x: auto; line-height: 1.6; }
  pre em { color: #888; font-style: normal; }

  .section-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f0f0f0; }

  /* Settings */
  .settings-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 24px; max-width: 420px; }
  .settings-card h2 { margin-bottom: 18px; }
  .settings-card .form-group { margin-bottom: 14px; }
  .settings-card .form-group label { display: block; margin-bottom: 4px; font-size: .85rem; font-weight: 600; color: #444; }
</style>
</head>
<body>

<nav>
  <span class="brand">DHCP Leases</span>
  <span class="user"><?= htmlspecialchars($username) ?></span>
  <a href="logout.php" class="logout">Logout</a>
</nav>

<div class="tabs">
  <a href="?tab=leases"  class="<?= $tab === 'leases'  ? 'active' : '' ?>">Leases</a>
  <a href="?tab=devices" class="<?= $tab === 'devices' ? 'active' : '' ?>">Devices &amp; Static IPs</a>
  <a href="?tab=settings" class="<?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
</div>

<div class="content">

<?php if ($message): ?>
  <div class="flash <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($tab === 'leases'): ?>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Device</th>
          <th>MAC Address</th>
          <th>IP Address</th>
          <th>Static IP</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
<?php
$shown_macs = [];
foreach ($devices as $dev):
    $mac = strtolower($dev['mac']);
    $shown_macs[] = $mac;
    $lease = $leases_by_mac[$mac] ?? null;
    $online = $lease !== null;
    $current_ip = $lease['ip'] ?? '—';
    $static_ip = $dev['static_ip'] ?? '';
?>
        <tr>
          <td><strong><?= htmlspecialchars($dev['title']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($dev['mac']) ?></td>
          <td class="mono"><?= htmlspecialchars($current_ip) ?></td>
          <td><?php if ($static_ip): ?><span class="badge static mono"><?= htmlspecialchars($static_ip) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><span class="badge <?= $online ? 'online' : 'offline' ?>"><?= $online ? 'Online' : 'Offline' ?></span></td>
        </tr>
<?php endforeach; ?>
<?php foreach ($leases_by_mac as $mac => $lease):
    if (in_array($mac, $shown_macs)) continue; ?>
        <tr>
          <td><em style="color:#999">Unknown</em></td>
          <td class="mono"><?= htmlspecialchars($mac) ?></td>
          <td class="mono"><?= htmlspecialchars($lease['ip']) ?></td>
          <td>—</td>
          <td><span class="badge online">Online</span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($tab === 'devices'): ?>

  <div class="card">
    <div class="section-header">
      <h2 style="margin:0">Named Devices</h2>
    </div>
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>MAC Address</th>
          <th>Static IP</th>
          <th>Current Lease</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($devices as $dev):
    $mac = strtolower($dev['mac']);
    $lease = $leases_by_mac[$mac] ?? null;
    $static_ip = $dev['static_ip'] ?? '';
?>
        <tr>
          <td><strong><?= htmlspecialchars($dev['title']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($dev['mac']) ?></td>
          <td>
            <form method="post" style="display:flex;gap:6px;align-items:center">
              <input type="hidden" name="action" value="set_static">
              <input type="hidden" name="mac" value="<?= htmlspecialchars($dev['mac']) ?>">
              <input type="text" name="ip" value="<?= htmlspecialchars($static_ip) ?>" placeholder="e.g. 192.168.1.100" style="width:160px">
              <button class="btn btn-primary btn-sm" type="submit">Set</button>
              <?php if ($static_ip): ?>
                <button class="btn btn-sm" style="background:#f0f0f0;color:#555" type="submit" onclick="this.previousElementSibling.previousElementSibling.value=''">Clear</button>
              <?php endif; ?>
            </form>
          </td>
          <td class="mono"><?= htmlspecialchars($lease['ip'] ?? '—') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Remove <?= htmlspecialchars($dev['title']) ?>?')">
              <input type="hidden" name="action" value="delete_device">
              <input type="hidden" name="mac" value="<?= htmlspecialchars($dev['mac']) ?>">
              <button class="btn btn-danger btn-sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>

    <!-- Add device form -->
    <form method="post">
      <input type="hidden" name="action" value="add_device">
      <div class="form-row">
        <div class="form-group">
          <label>Name</label>
          <input type="text" name="title" placeholder="e.g. laptop" required style="width:140px">
        </div>
        <div class="form-group">
          <label>MAC Address</label>
          <input type="text" name="mac" placeholder="aa:bb:cc:dd:ee:ff" required style="width:180px">
        </div>
        <div class="form-group">
          <label>Static IP (optional)</label>
          <input type="text" name="ip" placeholder="192.168.1.x" style="width:160px">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <button class="btn btn-success" type="submit">Add Device</button>
        </div>
      </div>
    </form>
  </div>

  <!-- dhcpd.conf snippet -->
  <div class="card">
    <div class="section-header">
      <h2 style="margin:0">dhcpd.conf Snippet</h2>
      <?php if ($config['configuration']['static_lease_file'] ?? ''): ?>
      <form method="post">
        <input type="hidden" name="action" value="apply_static">
        <button class="btn btn-success" type="submit" onclick="return confirm('Write to <?= htmlspecialchars($config['configuration']['static_lease_file']) ?> and reload DHCP?')">
          Apply &amp; Reload
        </button>
      </form>
      <?php endif; ?>
    </div>
    <div style="padding:16px">
      <?php if ($snippet): ?>
        <pre><?= htmlspecialchars($snippet) ?></pre>
        <?php if ($config['configuration']['static_lease_file'] ?? ''): ?>
          <p style="margin-top:10px;font-size:.82rem;color:#666">
            Writes to <code><?= htmlspecialchars($config['configuration']['static_lease_file']) ?></code> — make sure your dhcpd.conf includes it:<br>
            <code>include "<?= htmlspecialchars($config['configuration']['static_lease_file']) ?>";</code>
          </p>
        <?php endif; ?>
      <?php else: ?>
        <pre><em>No static IPs configured yet.</em></pre>
      <?php endif; ?>
    </div>
  </div>

<?php elseif ($tab === 'settings'): ?>

  <div class="settings-card">
    <h2>Change Password</h2>
    <form method="post">
      <input type="hidden" name="action" value="change_password">
      <div class="form-group">
        <label>Current Password</label>
        <input type="password" name="old_pass" required>
      </div>
      <div class="form-group">
        <label>New Password</label>
        <input type="password" name="new_pass" required>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_pass" required>
      </div>
      <br>
      <button class="btn btn-primary" type="submit">Update Password</button>
    </form>
  </div>

<?php endif; ?>

</div>
</body>
</html>
