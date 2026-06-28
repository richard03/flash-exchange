<?php
require_once __DIR__ . '/../lib/Session.php';
require_once __DIR__ . '/../lib/Transfer.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$sid  = $_GET['s'] ?? '';
$role = $_GET['role'] ?? '';

if (!in_array($role, ['pc', 'mobile'], true)) { http_response_code(400); exit; }

$session = Session::load($sid);
if (!$session || Session::isExpired($session)) {
    echo json_encode(['ok' => false, 'error' => 'expired']);
    exit;
}

Session::touch($sid, $role);

$partner           = $role === 'pc' ? 'mobile' : 'pc';
$partner_connected = Session::isConnected($session, $partner);
$pending           = Transfer::consumePending($sid, $role);

echo json_encode([
    'ok'               => true,
    'partner_connected' => $partner_connected,
    'action'           => $session['action'],
    'pending'          => $pending,
]);
