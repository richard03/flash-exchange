<?php
require_once __DIR__ . '/../lib/Session.php';

header('Content-Type: application/json');

$sid = $_POST['session_id'] ?? $_GET['s'] ?? '';
Session::delete($sid);
echo json_encode(['ok' => true]);
