<?php
require_once __DIR__.'/../app/compat.php';

// For the loopback development server: serve only the public document root.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$allowed = ['/', '/index.php', '/attendance.php', '/legacy-send.php', '/technical.php', '/simulator-submit.php', '/app.css', '/sidebar.css', '/API/', '/API/index.php', '/machine-simulator.php', '/machine-simulator.js', '/receiver-inspector.php', '/queue.php', '/settings.php', '/talenta-attendance.php'];
if (!in_array($path, $allowed, true)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}
if ($path === '/app.css') {
    header('Content-Type: text/css; charset=utf-8');
    readfile(__DIR__ . '/../public/app.css');
    return true;
}
if ($path === '/sidebar.css') {
    header('Content-Type: text/css; charset=utf-8');
    readfile(__DIR__ . '/../public/sidebar.css');
    return true;
}
if ($path === '/machine-simulator.js') {
    header('Content-Type: text/javascript; charset=utf-8');
    readfile(__DIR__ . '/../public/machine-simulator.js');
    return true;
}
$entry = compat_match($path, [[['/legacy-send.php'], fn() => 'legacy-send.php'], [['/simulator-submit.php'], fn() => 'simulator-submit.php'], [['/attendance.php'], fn() => 'attendance.php'], [['/technical.php'], fn() => 'technical.php'], [['/API/', '/API/index.php'], fn() => 'API/index.php'], [['/machine-simulator.php'], fn() => 'machine-simulator.php'], [['/receiver-inspector.php'], fn() => 'receiver-inspector.php'], [['/queue.php'], fn() => 'queue.php'], [['/settings.php'], fn() => 'settings.php'], [['/talenta-attendance.php'], fn() => 'talenta-attendance.php'], [null, fn() => 'index.php']]);
require __DIR__ . '/../public/' . $entry;
