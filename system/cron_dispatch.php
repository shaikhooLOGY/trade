<?php
// system/cron_dispatch.php — run by CRON every 5-10 mins
require_once __DIR__ . '/lib.php';

global $mysqli;

// 1) Pick pending events
$res = $mysqli->query("SELECT id, user_id, event, payload FROM system_events WHERE status='pending' ORDER BY id ASC LIMIT 50");
while($row = $res->fetch_assoc()){
    $event_id = (int)$row['id'];
    $event    = $row['event'];
    $payload  = json_decode($row['payload'] ?: '{}', true) ?: [];

    // 2) Load user (for template vars)
    $u = $mysqli->query("SELECT id, COALESCE(name,email) name FROM users WHERE id=".(int)$row['user_id']." LIMIT 1")->fetch_assoc();
    $vars = array_merge(['name'=>($u['name']??'User')], $payload);

    // 3) Pick template
    $tpl = sys_get_template($mysqli, $event, 'telegram');
    if(!$tpl){
        $mysqli->query("UPDATE system_events SET status='failed', sent_at=NOW() WHERE id=".$event_id);
        sys_log($mysqli,$event_id,'telegram', json_encode($payload),'no_template','error');
        continue;
    }

    // 4) Render + send
    $txt = sys_render_template($tpl['body'], $vars);
    $sent = sys_send_telegram($txt);

    // 5) Update + log
    $newStatus = ($sent['ok']??false) ? 'sent' : 'failed';
    $stmt=$mysqli->prepare("UPDATE system_events SET status=?, sent_at=NOW() WHERE id=?");
    $stmt->bind_param('si',$newStatus,$event_id); $stmt->execute(); $stmt->close();
    sys_log($mysqli,$event_id,'telegram', json_encode(['vars'=>$vars,'body'=>$txt]), json_encode($sent), $newStatus);
}

echo "ok ".now();