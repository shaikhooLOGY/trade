<?php
// system/lib.php — minimal helpers for the System User
// Uses your existing config.php ($mysqli)

// 1) Bring DB connection
require_once __DIR__ . '/../config.php';

// 2) Security key for internal triggers (set a long random string)
const SYS_INTERNAL_KEY = 'CHANGE_ME_TO_A_LONG_RANDOM_KEY_64CHARS';

// 3) Telegram (choose Telegram first; WhatsApp/email can be added later)
const SYS_TG_BOT_TOKEN = 'PASTE_YOUR_TELEGRAM_BOT_TOKEN';
const SYS_TG_CHAT_ID   = 'PASTE_YOUR_CHAT_ID_OR_CHANNEL_ID';

// 4) Tiny safe helpers
function s_val($arr,$k,$d=''){ return isset($arr[$k]) ? (string)$arr[$k] : $d; }
function j($x){ return json_encode($x, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
function now(){ return date('Y-m-d H:i:s'); }

// 5) Template render ({{var}} replacement)
function sys_render_template($tpl, array $vars){
    foreach($vars as $k=>$v){ $tpl = str_replace('{{'.$k.'}}', (string)$v, $tpl); }
    // leftover {{...}} clean
    return preg_replace('/\{\{[^}]+\}\}/', '', $tpl);
}

// 6) Telegram sender
function sys_send_telegram($text){
    if (!SYS_TG_BOT_TOKEN || !SYS_TG_CHAT_ID) return ['ok'=>false,'error'=>'telegram_not_configured'];
    $url = "https://api.telegram.org/bot".SYS_TG_BOT_TOKEN."/sendMessage";
    $payload = [
        'chat_id' => SYS_TG_CHAT_ID,
        'text'    => $text,
        'parse_mode' => 'HTML'
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload)
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code= curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if($err){ return ['ok'=>false,'error'=>$err]; }
    return ['ok'=>($code>=200 && $code<300), 'http'=>$code, 'body'=>$res];
}

// 7) Fetch template for an event
function sys_get_template(mysqli $db, $event, $channel='telegram'){
    $event = trim($event);
    $channel = trim($channel);
    $stmt = $db->prepare("SELECT id, subject, body FROM message_templates WHERE event=? AND channel=? AND active=1 LIMIT 1");
    $stmt->bind_param('ss',$event,$channel);
    $stmt->execute(); $res=$stmt->get_result(); $row=$res->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

// 8) Log send attempt
function sys_log(mysqli $db, $event_id, $channel, $payload, $response, $status){
    $stmt=$db->prepare("INSERT INTO system_logs (event_id, channel, payload, response, status, created_at) VALUES (?,?,?,?,?,NOW())");
    $stmt->bind_param('issss',$event_id,$channel,$payload,$response,$status);
    $stmt->execute(); $stmt->close();
}