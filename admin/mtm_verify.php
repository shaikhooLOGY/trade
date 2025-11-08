<?php
// admin/mtm_verify.php — MTM Verifier (per user / per model / per task) with dry-run if tables missing
require_once __DIR__ . '/../config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$is_admin = !empty($_SESSION['is_admin']);
$me_id    = (int)($_SESSION['user_id'] ?? 0);

header('X-Robots-Tag: noindex');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function t($s){ return trim((string)$s); }

// ---------- Safe checks ----------
function table_exists(mysqli $db, string $tbl): bool {
  $sql="SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?";
  $st=$db->prepare($sql); $st->bind_param('s',$tbl); $st->execute();
  $res=$st->get_result(); $ok=$res && ($r=$res->fetch_assoc()) && (int)$r['c']>0; $st->close();
  return $ok;
}
function col_exists(mysqli $db, string $tbl, string $col): bool {
  $sql="SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?";
  $st=$db->prepare($sql); $st->bind_param('ss',$tbl,$col); $st->execute();
  $res=$st->get_result(); $ok=$res && ($r=$res->fetch_assoc()) && (int)$r['c']>0; $st->close();
  return $ok;
}

$has_enroll   = table_exists($mysqli,'mtm_enrollments');
$has_progress = table_exists($mysqli,'mtm_task_progress');

$dry_run = !($has_enroll && $has_progress); // if schema not applied yet, we won’t write

// ---------- Inputs ----------
$action   = t($_REQUEST['action'] ?? 'run');          // run | recalc_all
$model_id = (int)($_REQUEST['model_id'] ?? 0);
$task_id  = (int)($_REQUEST['task_id']  ?? 0);
$user_id  = (int)($_REQUEST['user_id']  ?? 0);
$preview  = (int)($_REQUEST['preview']  ?? 0);        // 1 => evaluate only, no writes
$redirect = (int)($_REQUEST['redirect'] ?? 0);        // 1 => go back with flash

// Who are we verifying?
if ($user_id <= 0) $user_id = $me_id;

// Auth rules
if (!$me_id) { header('Location: /login.php'); exit; }
if (($action==='recalc_all' || ($user_id !== $me_id)) && !$is_admin) {
  header('HTTP/1.1 403 Forbidden'); exit('Access denied');
}

// ---------- Load model/tasks ----------
function fetch_model(mysqli $db, int $model_id){
  if ($model_id<=0) return null;
  $st=$db->prepare("SELECT * FROM mtm_models WHERE id=? LIMIT 1");
  $st->bind_param('i',$model_id); $st->execute();
  $r = $st->get_result()->fetch_assoc(); $st->close();
  return $r ?: null;
}
function fetch_tasks(mysqli $db, int $model_id, int $task_id=0): array {
  if ($task_id>0){
    $st=$db->prepare("SELECT * FROM mtm_tasks WHERE id=? LIMIT 1");
    $st->bind_param('i',$task_id); $st->execute(); $res=$st->get_result();
    $row=$res->fetch_assoc(); $st->close();
    return $row? [$row] : [];
  }
  if ($model_id>0){
    $st=$db->prepare("SELECT * FROM mtm_tasks WHERE model_id=? ORDER BY sort_order ASC, id ASC");
    $st->bind_param('i',$model_id); $st->execute(); $res=$st->get_result();
    $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[]; $st->close();
    return $rows;
  }
  return [];
}
function decode_rule($json){
  if ($json===''||$json===null) return [];
  $d = json_decode($json,true);
  return is_array($d)? $d : [];
}

// ---------- Fetch trades for evaluation ----------
$has_exit_date = col_exists($mysqli,'trades','exit_date');
$has_pospct    = col_exists($mysqli,'trades','position_percent');
$has_link      = col_exists($mysqli,'trades','analysis_link');

function fetch_trades_for_user(mysqli $db, int $user_id): array {
  $parts = [
    "id","symbol","entry_price","stop_loss","target_price","exit_price",
    "COALESCE(outcome,'') outcome","entry_date"
  ];
  if (col_exists($db,'trades','exit_date'))        $parts[]="exit_date";
  if (col_exists($db,'trades','position_percent')) $parts[]="position_percent";
  if (col_exists($db,'trades','analysis_link'))    $parts[]="analysis_link";

  $sql="SELECT ".implode(',', $parts)."
        FROM trades
        WHERE user_id=? AND deleted_at IS NULL
        ORDER BY COALESCE(exit_date, entry_date) DESC, id DESC";
  $st=$db->prepare($sql); $st->bind_param('i',$user_id); $st->execute();
  $res=$st->get_result(); $rows=$res?$res->fetch_all(MYSQLI_ASSOC):[]; $st->close();
  return $rows;
}

// ---------- Evaluator ----------
function is_closed(array $t): bool {
  $out = strtoupper(trim((string)($t['outcome'] ?? '')));
  $exit= (float)($t['exit_price'] ?? 0);
  $ed  = trim((string)($t['exit_date'] ?? ''));
  return ($out!=='' && $out!=='OPEN') || $exit>0 || $ed!=='';
}
function risk_pct($entry,$sl){
  if ($entry===null||$sl===null) return null;
  $e=(float)$entry; $s=(float)$sl; if ($e<=0) return null;
  return (($e-$s)/$e)*100.0;
}
function rr_ratio($entry,$sl,$target){
  $r = risk_pct($entry,$sl);
  if ($r===null || $r==0) return null;
  if ($target===null) return null;
  $e=(float)$entry; $tg=(float)$target; if ($e<=0) return null;
  $reward = (($tg-$e)/$e)*100.0;
  return $reward / $r;
}
function within_days(string $date, int $days): bool {
  if ($date==='') return false;
  $ts = strtotime($date);
  if (!$ts) return false;
  $cut = strtotime("-{$days} days");
  return $ts >= $cut;
}

function eval_task_rules(array $task, array $trades): array {
  $rules = decode_rule($task['rule_json'] ?? '');
  $title = (string)($task['title'] ?? 'Task');
  $level = (string)($task['level'] ?? 'easy');

  // Defaults
  $mode              = $rules['mode']               ?? 'both';   // not strictly enforced (no is_real flag yet)
  $min_trades        = (int)($rules['min_trades']   ?? 0);
  $time_window_days  = (int)($rules['time_window_days'] ?? 0);
  $require_sl        = (int)($rules['require_sl']   ?? 0) === 1;
  $require_link      = (int)($rules['require_analysis_link'] ?? 0) === 1;
  $max_risk_pct      = isset($rules['max_risk_pct'])      ? (float)$rules['max_risk_pct']      : null;
  $max_position_pct  = isset($rules['max_position_pct'])  ? (float)$rules['max_position_pct']  : null;
  $min_rr            = isset($rules['min_rr'])            ? (float)$rules['min_rr']            : null;
  $weekly_min_trades = (int)($rules['weekly_min_trades'] ?? 0);
  $weeks             = (int)($rules['weeks'] ?? 0);
  $closed_only       = (int)($rules['closed_only'] ?? 1) === 1;

  // Filter window
  $pool = [];
  foreach ($trades as $tr) {
    if ($closed_only && !is_closed($tr)) continue;

    if ($time_window_days>0) {
      $d = '';
      if (is_closed($tr))       $d = trim((string)($tr['exit_date'] ?? ''));
      if ($d==='')              $d = trim((string)($tr['entry_date'] ?? ''));
      if (!within_days($d, $time_window_days)) continue;
    }

    if ($require_sl && !(isset($tr['stop_loss']) && (float)$tr['stop_loss']>0)) continue;
    if ($require_link && !(isset($tr['analysis_link']) && trim((string)$tr['analysis_link'])!=='')) continue;

    if ($max_risk_pct!==null) {
      $rp = risk_pct($tr['entry_price'] ?? null, $tr['stop_loss'] ?? null);
      if ($rp===null || $rp > $max_risk_pct + 1e-9) continue;
    }

    if ($max_position_pct!==null) {
      $pp = $tr['position_percent'] ?? null;
      if ($pp===null || (float)$pp > $max_position_pct + 1e-9) continue;
    }

    if ($min_rr!==null) {
      $rr = rr_ratio($tr['entry_price'] ?? null, $tr['stop_loss'] ?? null, $tr['target_price'] ?? null);
      if ($rr===null || $rr < $min_rr - 1e-9) continue;
    }

    $pool[] = $tr;
  }

  // Weekly cadence check (optional)
  $weekly_ok = true;
  if ($weekly_min_trades>0 && $weeks>0) {
    $wk = [];
    foreach ($pool as $tr) {
      $d = trim((string)($tr['exit_date'] ?? $tr['entry_date'] ?? ''));
      if ($d==='') continue;
      $dt = new DateTime($d);
      $y  = $dt->format('o');
      $w  = $dt->format('W');
      $key= $y.'-W'.$w;
      $wk[$key] = ($wk[$key] ?? 0) + 1;
    }
    krsort($wk);
    $countWeeks=0; $weeks_ok=0;
    foreach ($wk as $key=>$cnt) {
      $countWeeks++;
      if ($cnt >= $weekly_min_trades) $weeks_ok++;
      if ($countWeeks >= $weeks) break;
    }
    $weekly_ok = ($weeks_ok >= $weeks);
  }

  $min_ok = ($min_trades<=0) ? true : (count($pool) >= $min_trades);
  $passed = $min_ok && $weekly_ok;

  return [
    'task_id'   => (int)$task['id'],
    'title'     => $title,
    'level'     => $level,
    'passed'    => $passed,
    'evidence'  => array_map(function($r){
      return [
        'id'         => $r['id'],
        'symbol'     => $r['symbol'],
        'entry_date' => $r['entry_date'],
        'exit_date'  => $r['exit_date'] ?? null
      ];
    }, $pool),
    'counts'    => ['matched'=>count($pool), 'required'=>$min_trades],
    'weekly'    => ['required_weeks'=>$weeks,'min_per_week'=>$weekly_min_trades,'ok'=>$weekly_ok],
    'rules'     => $rules
  ];
}

// ---------- Run modes ----------
$out = [
  'dry_run'  => $dry_run,
  'user_id'  => $user_id,
  'model_id' => $model_id,
  'task_id'  => $task_id,
  'action'   => $action,
  'results'  => []
];

if ($action === 'recalc_all') {
  // Admin only: for a model, evaluate all enrolled users
  if ($model_id <= 0) { $_SESSION['flash']="Model required"; if ($redirect) { header("Location: mtm_models.php"); exit; } }

  if (!$is_admin) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

  // find enrolled users (fallback: if no table yet, do nothing)
  $users = [];
  if ($has_enroll) {
    $st=$mysqli->prepare("SELECT DISTINCT user_id FROM mtm_enrollments WHERE model_id=? AND status IN ('active','completed')");
    $st->bind_param('i',$model_id); $st->execute();
    $res=$st->get_result(); while($r=$res->fetch_assoc()){ $users[]=(int)$r['user_id']; } $st->close();
  } else {
    $_SESSION['flash'] = "Schema not applied yet — dry run only.";
    if ($redirect) { header("Location: mtm_models.php"); exit; }
  }

  $model = fetch_model($mysqli,$model_id);
  $tasks = fetch_tasks($mysqli,$model_id,0);

  foreach ($users as $uid) {
    $trades = fetch_trades_for_user($mysqli,$uid);
    $completed = 0;
    foreach ($tasks as $t) {
      $res = eval_task_rules($t,$trades);
      $out['results'][] = ['user_id'=>$uid] + $res;

      if (!$dry_run) {
        $status = $res['passed'] ? 'completed' : 'pending';
        $eviCnt = (int)$res['counts']['matched'];
        $details = json_encode($res, JSON_UNESCAPED_SLASHES);
        $q="INSERT INTO mtm_task_progress (user_id, task_id, status, evidence_count, last_checked_at, details_json)
            VALUES (?,?,?,?,NOW(),?)
            ON DUPLICATE KEY UPDATE status=VALUES(status), evidence_count=VALUES(evidence_count), last_checked_at=NOW(), details_json=VALUES(details_json)";
        $st=$mysqli->prepare($q); $st->bind_param('iisds',$uid,$res['task_id'],$status,$eviCnt,$details); $st->execute(); $st->close();
      }
      if ($res['passed']) $completed++;
    }
    if (!$dry_run && $has_enroll && count($tasks)>0) {
      $pct = round(($completed / count($tasks)) * 100);
      $q="UPDATE mtm_enrollments SET progress_pct=?, status=CASE WHEN ?>=100 THEN 'completed' ELSE 'active' END, updated_at=NOW()
          WHERE user_id=? AND model_id=?";
      $st=$mysqli->prepare($q); $st->bind_param('iiii',$pct,$pct,$uid,$model_id); $st->execute(); $st->close();
    }
  }

  if ($redirect) {
    $_SESSION['flash'] = "Recalculated for ".count($users)." users".($dry_run?" (dry-run)":"").".";
    header("Location: mtm_models.php"); exit;
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
  exit;
}

// Single user flow (run/preview)
$model  = $model_id>0 ? fetch_model($mysqli,$model_id) : null;
$tasks  = fetch_tasks($mysqli,$model_id,$task_id);
$trades = fetch_trades_for_user($mysqli,$user_id);

$completed = 0;
foreach ($tasks as $t) {
  $res = eval_task_rules($t,$trades);
  $out['results'][] = $res;

  if (!$dry_run && !$preview) {
    $status  = $res['passed'] ? 'completed' : 'pending';
    $eviCnt  = (int)$res['counts']['matched'];
    $details = json_encode($res, JSON_UNESCAPED_SLASHES);
    $q="INSERT INTO mtm_task_progress (user_id, task_id, status, evidence_count, last_checked_at, details_json)
        VALUES (?,?,?,?,NOW(),?)
        ON DUPLICATE KEY UPDATE status=VALUES(status), evidence_count=VALUES(evidence_count), last_checked_at=NOW(), details_json=VALUES(details_json)";
    $st=$mysqli->prepare($q); $st->bind_param('iisds',$user_id,$res['task_id'],$status,$eviCnt,$details); $st->execute(); $st->close();
  }
  if ($res['passed']) $completed++;
}

if (!$dry_run && !$preview && $model && count($tasks)>0 && $has_enroll) {
  $pct = round(($completed / count($tasks)) * 100);
  $q="UPDATE mtm_enrollments SET progress_pct=?, status=CASE WHEN ?>=100 THEN 'completed' ELSE 'active' END, updated_at=NOW()
      WHERE user_id=? AND model_id=?";
  $st=$mysqli->prepare($q); $st->bind_param('iiii',$pct,$pct,$user_id,$model_id); $st->execute(); $st->close();
}

if ($redirect) {
  $_SESSION['flash'] = "Verification ".($preview?'(preview) ':'')."done".($dry_run?' (dry-run)':'').".";
  $back = 'mtm_models.php';
  if ($task_id>0) $back = 'mtm_tasks.php?model_id='.$model_id;
  header("Location: ".$back); exit;
}

// Default: JSON out
header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);