<?php
session_start();
if (isset($_SESSION['id'])) { header('Location: ..'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username']) && !empty($_POST['pass'])) {
    $config_path = '../../config.json';
    $config = json_decode(file_get_contents($config_path), true);
    if (!$config) die('Failed to parse config.json');

    foreach ($config['users'] as &$user) {
        if ($user['name'] !== $_POST['username']) continue;

        $hash = $user['pass'];
        $valid = password_verify($_POST['pass'], $hash) || $hash === $_POST['pass'];

        if ($valid) {
            // Upgrade plaintext password to bcrypt on first login
            if (!str_starts_with($hash, '$2y$')) {
                $user['pass'] = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            $_SESSION['id'] = $user['id'];
            header('Location: ..');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
        break;
    }
    if (!$error) $error = 'Invalid username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login – DHCP Leases</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }

  .card { background: #fff; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,.1); padding: 40px 36px; width: 340px; }
  h1 { font-size: 1.3rem; font-weight: 700; color: #1a1a2e; margin-bottom: 6px; }
  p.sub { font-size: .85rem; color: #888; margin-bottom: 28px; }

  .form-group { margin-bottom: 16px; }
  label { display: block; font-size: .82rem; font-weight: 600; color: #444; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .4px; }
  input { width: 100%; border: 1px solid #ddd; border-radius: 6px; padding: 9px 12px; font-size: .95rem; outline: none; transition: border-color .15s; }
  input:focus { border-color: #4a90d9; }

  .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 6px; padding: 10px 14px; font-size: .88rem; margin-bottom: 18px; }

  button { width: 100%; background: #1a1a2e; color: #fff; border: none; border-radius: 6px; padding: 11px; font-size: .95rem; font-weight: 600; cursor: pointer; margin-top: 8px; transition: background .15s; }
  button:hover { background: #2d2d4e; }
</style>
</head>
<body>
<div class="card">
  <h1>DHCP Leases</h1>
  <p class="sub">Sign in to continue</p>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <div class="form-group">
      <label>Username</label>
      <input type="text" name="username" autocomplete="username" autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Password</label>
      <input type="password" name="pass" autocomplete="current-password">
    </div>
    <button type="submit">Sign In</button>
  </form>
</div>
</body>
</html>
