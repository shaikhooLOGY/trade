<?php
// system/trigger.php — create a system_event (secure internal endpoint)
// Use it from your app (server-to-server), not public UI.

require_once __DIR__ . '/lib.php';

// 1) Security check (internal key via header or ?key=)
$key = $_SERVER['HTTP_X_SYS_KEY'] ?? ($_GET['key'] ?? '');
if ($key !== SYS_INTERNAL_KEY) { http_response_code(401); echo "unauthorized"; exit; }

// 2) Read payload
$input = $_POST ?: json_decode(file_get_contents('php://input'), true);
$event = s_val($input,'event');
$user_id = (int)s_val($input,'user_id',0);
$payload = s_val($input,'data','{}');

if(!$event || $user_id<=0){
    http_response_code(400); echo "missing event or user_id"; exit;
}

// 3) Insert event
global $mysqli;
$stmt=$mysqli->prepare("INSERT INTO system_events (user_id,event,payload,status,created_at) VALUES (?,?,?, 'pending', NOW())");
$stmt->bind_param('iss',$user_id,$event,$payload);
$ok=$stmt->execute(); $id=$mysqli->insert_id; $stmt->close();

header('Content-Type: application/json');
echo json_encode(['ok'=>$ok,'event_id'=>$id]);