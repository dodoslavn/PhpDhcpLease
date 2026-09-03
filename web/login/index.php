<?php
session_start();
require_once '../includes/lang.php';

if (isset($_SESSION['id'])) { header('Location: ..'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username']) && !empty($_POST['pass'])) {
    $config_path = '../../config.json';
    $config = json_decode(file_get_contents($config_path), true);
    if (!$config) die('Failed to parse config.json');

    $found = false;
    foreach ($config['users'] as &$user) {
        if ($user['name'] !== $_POST['username']) continue;
        $found = true;

        $hash  = $user['pass'];
        $valid = password_verify($_POST['pass'], $hash) || $hash === $_POST['pass'];

        if ($valid) {
            if (!str_starts_with($hash, '$2y$')) {
                $user['pass'] = password_hash($_POST['pass'], PASSWORD_BCRYPT);
                file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            $_SESSION['id'] = $user['id'];
            header('Location: ..');
            exit;
        }
        $error = t('login.err.invalid');
        break;
    }
    if (!$found) $error = t('login.err.invalid');
}

$lang = $GLOBALS['_LANG_CODE'];
$app  = t('app.name');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= t('login.title') ?> — <?= htmlspecialchars($app) ?></title>
<link rel="icon" href="../assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="login-wrap">
  <div class="login-outer">

    <div class="login-header">
      <img src="../assets/logo.svg" alt="logo" height="44">
      <div class="login-app-name"><?= htmlspecialchars($app) ?></div>
    </div>

    <div class="login-box">
      <h1><?= t('login.title') ?></h1>
      <?php if ($error): ?>
        <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <label>
          <?= t('login.username') ?>
          <input type="text" name="username" autocomplete="username" autofocus
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
        </label>
        <label>
          <?= t('login.password') ?>
          <input type="password" name="pass" autocomplete="current-password">
        </label>
        <button class="btn" type="submit"><?= t('login.submit') ?></button>
      </form>
    </div>

    <?= lang_switcher('../login/') ?>

  </div>
</div>
</body>
</html>
