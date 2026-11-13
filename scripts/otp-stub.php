<?php

// A stand-in for MSG91 for Start-Otp-Mock.ps1. It listens on 127.0.0.1 only, reaches no
// phone and bills nothing. It plays the handset, so it keeps each test code for the
// tester to read: real codes are never stored like this, only as hashes in the database.

date_default_timezone_set('Asia/Kolkata');

$dir = getenv('USERPROFILE').'/E-Notary-backups/otp-mock';
$codes = "$dir/codes.json";
$modeFile = "$dir/mode.txt";
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (! is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$mode = is_file($modeFile) ? trim(file_get_contents($modeFile)) : 'accept';
$modes = [
    'accept' => 'Accept and show the code',
    'reject' => 'Reject, as MSG91 does for a bad template',
    'ip'     => 'Refuse, as MSG91 does for an unlisted IP',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/otp') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($mode === 'ip') {
        exit(json_encode(['message' => 'IP is not whitelisted', 'type' => 'error', 'code' => '418']));
    }

    if ($mode === 'reject') {
        exit(json_encode(['message' => 'Invalid template', 'type' => 'error', 'code' => '211']));
    }

    $all = is_file($codes) ? json_decode(file_get_contents($codes), true) : [];
    array_unshift($all, ['time' => date('H:i:s'), 'mobile' => (string) ($body['mobile'] ?? ''), 'code' => (string) ($body['otp'] ?? '')]);
    file_put_contents($codes, json_encode(array_slice($all, 0, 10)));

    exit(json_encode(['type' => 'success', 'request_id' => 'mock'.bin2hex(random_bytes(8))]));
}

if (isset($_GET['mode'], $modes[$_GET['mode']])) {
    file_put_contents($modeFile, $_GET['mode']);
    header('Location: /');
    exit;
}

$rows = is_file($codes) ? json_decode(file_get_contents($codes), true) : [];
$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mock SMS</title>
<style>body{font:16px system-ui;margin:24px;max-width:640px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:8px;text-align:left}.code{font:700 20px monospace}a{margin-right:12px}</style></head>
<body>
<h1>Mock SMS</h1>
<p><strong>Not a real SMS.</strong> Nothing here reaches a phone or costs anything. Refresh after pressing Send code.</p>
<table><tr><th>Time</th><th>Number</th><th>Code</th></tr>
<?php foreach ($rows as $row) { ?><tr><td><?= $e($row['time']) ?></td><td><?= $e($row['mobile']) ?></td><td class="code"><?= $e($row['code']) ?></td></tr><?php } ?>
<?php if (! $rows) { ?><tr><td colspan="3">No codes yet.</td></tr><?php } ?>
</table>
<h2>Provider behaviour: <?= $e($modes[$mode] ?? $mode) ?></h2>
<p><?php foreach ($modes as $key => $label) { ?><a href="/?mode=<?= $e($key) ?>"><?= $e($label) ?></a><br><?php } ?></p>
</body>
</html>
