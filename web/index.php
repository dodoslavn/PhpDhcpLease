<?php
session_start();
require_once 'includes/lang.php';

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
        $message = t('devices.static.updated');
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
            $message = t('devices.added', ['name' => $title]);
        } else {
            $message = t('devices.err.invalid');
            $message_type = 'error';
        }
    }

    if ($action === 'delete_device') {
        $mac = strtolower(trim($_POST['mac'] ?? ''));
        $config['data'] = array_values(array_filter($config['data'], fn($d) => strtolower($d['mac']) !== $mac));
        save_config($config_path, $config);
        $message = t('devices.removed');
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
                if ($rc2 === 0) {
                    $message = t('snippet.applied');
                } else {
                    $message = t('snippet.err.reload', ['msg' => implode(' ', $out2)]);
                    $message_type = 'error';
                }
            } elseif ($rc1 === 0) {
                $message = t('snippet.written');
            } else {
                $message = t('snippet.err.write', ['msg' => implode(' ', $out1)]);
                $message_type = 'error';
            }
        } else {
            $message = t('snippet.err.no_file');
            $message_type = 'error';
        }
    }

    if ($action === 'change_password') {
        $old  = $_POST['old_pass'] ?? '';
        $new1 = $_POST['new_pass'] ?? '';
        $new2 = $_POST['confirm_pass'] ?? '';
        foreach ($config['users'] as &$user) {
            if ($user['id'] == $_SESSION['id']) {
                $hash  = $user['pass'];
                $valid = password_verify($old, $hash) || $hash === $old;
                if (!$valid) {
                    $message = t('settings.err.wrong'); $message_type = 'error';
                } elseif ($new1 !== $new2 || strlen($new1) < 4) {
                    $message = t('settings.err.mismatch'); $message_type = 'error';
                } else {
                    $user['pass'] = password_hash($new1, PASSWORD_BCRYPT);
                    save_config($config_path, $config);
                    $message = t('settings.ok');
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
        if ($mac) $leases_by_mac[$mac] = ['mac' => $mac, 'ip' => $ip];
    }
}

$devices = $config['data'] ?? [];
usort($devices, fn($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

$snippet = build_snippet($devices);
$tab     = $_GET['tab'] ?? 'leases';
$appname = t('app.name');
$lang    = $GLOBALS['_LANG_CODE'];
$username = current_username($config);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($appname) ?></title>
<link rel="icon" href="assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav>
  <a class="nav-brand" href="?tab=leases">
    <img src="assets/logo.svg" alt="logo" height="26">
    <?= htmlspecialchars($appname) ?>
  </a>
  <ul>
    <li><a href="?tab=leases"  <?= $tab === 'leases'  ? 'class="active"' : '' ?>><?= t('nav.leases') ?></a></li>
    <li><a href="?tab=devices" <?= $tab === 'devices' ? 'class="active"' : '' ?>><?= t('nav.devices') ?></a></li>
    <li><a href="?tab=settings" <?= $tab === 'settings' ? 'class="active"' : '' ?>><?= t('nav.settings') ?></a></li>
  </ul>
  <div class="nav-right">
    <span class="nav-user"><?= htmlspecialchars($username) ?></span>
    <a href="logout.php" class="nav-logout"><?= t('nav.logout') ?></a>
  </div>
</nav>

<main>

<?php if ($message): ?>
  <div class="flash flash-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($tab === 'leases'): ?>

  <div class="card">
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= t('leases.col.device') ?></th>
          <th><?= t('leases.col.mac') ?></th>
          <th><?= t('leases.col.ip') ?></th>
          <th><?= t('leases.col.static_ip') ?></th>
          <th><?= t('leases.col.status') ?></th>
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
    $static_ip = $dev['static_ip'] ?? '';
?>
        <tr>
          <td><strong><?= htmlspecialchars($dev['title']) ?></strong></td>
          <td class="mono"><?= htmlspecialchars($dev['mac']) ?></td>
          <td class="mono"><?= htmlspecialchars($lease['ip'] ?? '—') ?></td>
          <td><?php if ($static_ip): ?><span class="badge badge-static mono"><?= htmlspecialchars($static_ip) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><span class="badge <?= $online ? 'badge-online' : 'badge-offline' ?>"><?= $online ? t('leases.status.online') : t('leases.status.offline') ?></span></td>
        </tr>
<?php endforeach; ?>
<?php foreach ($leases_by_mac as $mac => $lease):
    if (in_array($mac, $shown_macs)) continue; ?>
        <tr>
          <td><em class="muted"><?= t('leases.unknown') ?></em></td>
          <td class="mono"><?= htmlspecialchars($mac) ?></td>
          <td class="mono"><?= htmlspecialchars($lease['ip']) ?></td>
          <td>—</td>
          <td><span class="badge badge-online"><?= t('leases.status.online') ?></span></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php elseif ($tab === 'devices'): ?>

  <div class="card">
    <div class="card-header"><h2><?= t('devices.title') ?></h2></div>
    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><?= t('devices.col.name') ?></th>
          <th><?= t('devices.col.mac') ?></th>
          <th><?= t('devices.col.static_ip') ?></th>
          <th><?= t('devices.col.current') ?></th>
          <th><?= t('devices.col.actions') ?></th>
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
            <form method="post" style="display:flex;gap:5px;align-items:center">
              <input type="hidden" name="action" value="set_static">
              <input type="hidden" name="mac" value="<?= htmlspecialchars($dev['mac']) ?>">
              <input type="text" name="ip" value="<?= htmlspecialchars($static_ip) ?>" placeholder="192.168.1.x" style="width:150px">
              <button class="btn btn-sm" type="submit"><?= t('devices.set') ?></button>
              <?php if ($static_ip): ?>
                <button class="btn btn-sm btn-ghost" type="submit"
                  onclick="this.previousElementSibling.previousElementSibling.value=''"><?= t('devices.clear') ?></button>
              <?php endif; ?>
            </form>
          </td>
          <td class="mono"><?= htmlspecialchars($lease['ip'] ?? '—') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('<?= t('devices.remove.confirm', ['name' => htmlspecialchars($dev['title'], ENT_QUOTES)]) ?>')">
              <input type="hidden" name="action" value="delete_device">
              <input type="hidden" name="mac" value="<?= htmlspecialchars($dev['mac']) ?>">
              <button class="btn btn-sm btn-danger" type="submit"><?= t('devices.remove') ?></button>
            </form>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="add_device">
      <div class="form-row">
        <div class="form-group">
          <label><?= t('devices.add.name') ?></label>
          <input type="text" name="title" placeholder="e.g. laptop" style="width:130px" required>
        </div>
        <div class="form-group">
          <label><?= t('devices.add.mac') ?></label>
          <input type="text" name="mac" placeholder="aa:bb:cc:dd:ee:ff" style="width:175px" required>
        </div>
        <div class="form-group">
          <label><?= t('devices.add.ip') ?></label>
          <input type="text" name="ip" placeholder="192.168.1.x" style="width:150px">
        </div>
        <div class="form-group" style="justify-content:flex-end">
          <button class="btn btn-success" type="submit"><?= t('devices.add') ?></button>
        </div>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-header">
      <h2><?= t('snippet.title') ?></h2>
      <?php $static_file = $config['configuration']['static_lease_file'] ?? ''; ?>
      <?php if ($static_file): ?>
      <form method="post">
        <input type="hidden" name="action" value="apply_static">
        <button class="btn btn-success btn-sm" type="submit"
          onclick="return confirm('<?= t('snippet.apply.confirm', ['file' => htmlspecialchars($static_file, ENT_QUOTES)]) ?>')"><?= t('snippet.apply') ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php if ($snippet): ?>
      <pre><?= htmlspecialchars($snippet) ?></pre>
      <?php if ($static_file): ?>
        <p class="hint"><?= t('snippet.include_hint', ['file' => htmlspecialchars($static_file)]) ?><br>
        <code>include "<?= htmlspecialchars($static_file) ?>";</code></p>
      <?php endif; ?>
    <?php else: ?>
      <pre><em><?= t('snippet.empty') ?></em></pre>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'settings'): ?>

  <div class="card settings-wrap">
    <div class="card-header"><h2><?= t('settings.title') ?></h2></div>
    <form method="post">
      <input type="hidden" name="action" value="change_password">
      <div class="form-grid">
        <label>
          <?= t('settings.current') ?>
          <input type="password" name="old_pass" required>
        </label>
        <label>
          <?= t('settings.new') ?>
          <input type="password" name="new_pass" required>
        </label>
        <label>
          <?= t('settings.confirm') ?>
          <input type="password" name="confirm_pass" required>
        </label>
        <div>
          <button class="btn" type="submit"><?= t('settings.submit') ?></button>
        </div>
      </div>
    </form>
  </div>

<?php endif; ?>

</main>

<footer><?= lang_switcher() ?></footer>

</body>
</html>
