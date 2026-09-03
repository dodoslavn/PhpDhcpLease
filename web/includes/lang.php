<?php
// Handle ?lang=XX switch — sets cookie and redirects back
if (isset($_GET['lang'])) {
    $requested = preg_replace('/[^a-z]/', '', strtolower($_GET['lang']));
    $file = __DIR__ . '/../lang/' . $requested . '.php';
    if (is_file($file)) {
        setcookie('lang', $requested, time() + 60 * 60 * 24 * 365, '/');
        $redirect = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
        header('Location: ' . $redirect);
        exit;
    }
}

function _detect_lang(): string {
    if (isset($_COOKIE['lang'])) {
        $c = preg_replace('/[^a-z]/', '', strtolower($_COOKIE['lang']));
        if (is_file(__DIR__ . '/../lang/' . $c . '.php')) return $c;
    }
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
            $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            $code = preg_replace('/[^a-z]/', '', $code);
            if ($code !== '' && is_file(__DIR__ . '/../lang/' . $code . '.php')) return $code;
        }
    }
    return 'en';
}

$GLOBALS['_LANG_CODE'] = _detect_lang();

$_lang_base = require __DIR__ . '/../lang/en.php';
if ($GLOBALS['_LANG_CODE'] !== 'en') {
    $override = require __DIR__ . '/../lang/' . $GLOBALS['_LANG_CODE'] . '.php';
    $GLOBALS['_LANG'] = array_merge($_lang_base, $override);
} else {
    $GLOBALS['_LANG'] = $_lang_base;
}

function t(string $key, array $vars = []): string {
    $str = $GLOBALS['_LANG'][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $str = str_replace('{' . $k . '}', $v, $str);
    }
    return $str;
}

function lang_list(): array {
    $langs = [];
    foreach (glob(__DIR__ . '/../lang/*.php') as $file) {
        $code = basename($file, '.php');
        $strings = require $file;
        $langs[$code] = $strings['lang.name'] ?? strtoupper($code);
    }
    ksort($langs);
    return $langs;
}

function lang_switcher(string $base_url = ''): string {
    $langs   = lang_list();
    $current = $GLOBALS['_LANG_CODE'];
    if (!$base_url) $base_url = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
    $items = [];
    foreach ($langs as $code => $name) {
        if ($code === $current) {
            $items[] = '<span class="footer-lang-active">' . htmlspecialchars($name) . '</span>';
        } else {
            $url = htmlspecialchars($base_url . '?lang=' . urlencode($code));
            $items[] = '<a href="' . $url . '">' . htmlspecialchars($name) . '</a>';
        }
    }
    return '<div class="footer-langs">' . implode(' · ', $items) . '</div>';
}
