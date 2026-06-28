<?php
require_once __DIR__ . '/../lib/Session.php';
require_once __DIR__ . '/../lib/Transfer.php';

$sid = $_GET['s'] ?? '';
$tid = $_GET['transfer_id'] ?? '';

$session = Session::load($sid);
if (!$session || Session::isExpired($session)) {
    http_response_code(404); echo 'Relace nenalezena.'; exit;
}

$pending = $session['pending'] ?? null;
if (!$pending || $pending['transfer_id'] !== $tid || $pending['type'] !== 'file') {
    http_response_code(404); echo 'Přenos nenalezen.'; exit;
}

$filename = basename($pending['filename'] ?? 'soubor');
$bin = Transfer::deliverFile($sid, $tid);
if (!$bin) {
    http_response_code(404); echo 'Soubor nenalezen.'; exit;
}

ignore_user_abort(true);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . filesize($bin));
header('Cache-Control: no-store');
readfile($bin);
unlink($bin);
