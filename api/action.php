<?php
require_once __DIR__ . '/../lib/Session.php';
require_once __DIR__ . '/../lib/Transfer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$sid    = $_POST['session_id'] ?? '';
$action = $_POST['action'] ?? '';
$role   = $_POST['role'] ?? 'mobile';

if (!in_array($role, ['pc', 'mobile'], true)) { http_response_code(400); exit; }

$session = Session::load($sid);
if (!$session || Session::isExpired($session)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

Session::touch($sid, $role);
$ok = Transfer::setAction($sid, $action);
echo json_encode(['ok' => $ok]);
