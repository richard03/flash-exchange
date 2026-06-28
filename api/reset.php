<?php
require_once __DIR__ . '/../lib/Session.php';
require_once __DIR__ . '/../lib/Transfer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$sid  = $_POST['session_id'] ?? '';
$role = $_POST['role'] ?? '';

$session = Session::load($sid);
if (!$session || Session::isExpired($session)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

Session::touch($sid, $role);
Transfer::reset($sid);
echo json_encode(['ok' => true]);
