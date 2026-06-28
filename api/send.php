<?php
require_once __DIR__ . '/../lib/Session.php';
require_once __DIR__ . '/../lib/Transfer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$sid  = $_POST['session_id'] ?? '';
$role = $_POST['role'] ?? '';
$type = $_POST['type'] ?? '';

if (!in_array($role, ['pc', 'mobile'], true) || !in_array($type, ['text', 'file'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_params']);
    exit;
}

$session = Session::load($sid);
if (!$session || Session::isExpired($session)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

Session::touch($sid, $role);

if ($type === 'text') {
    $text = trim($_POST['text'] ?? '');
    if ($text === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'empty_text']);
        exit;
    }
    $tid = Transfer::storeText($sid, $role, $text);
} else {
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'upload_error', 'code' => $f['error'] ?? -1]);
        exit;
    }
    $tid = Transfer::storeFile($sid, $role, $f);
}

if (!$tid) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'store_failed']);
    exit;
}

echo json_encode(['ok' => true, 'transfer_id' => $tid]);
