<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_verify($t){ return isset($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'],$t); }
function require_login(){ if(empty($_SESSION['user_id'])){ header('Location: /login.php'); exit; } }
function flash_set($m){ $_SESSION['flash']=$m; }
function flash_pop(){ $m=$_SESSION['flash']??''; if($m!=='') unset($_SESSION['flash']); return $m; }

require_login();
$userId=(int)($_SESSION['user_id']??0);
$isAdmin=!empty($_SESSION['is_admin']);
$tradeId=(int)($_GET['id']??$_POST['id']??0);

if($tradeId<=0){ flash_set('Invalid trade id.'); header('Location: /dashboard.php'); exit; }

// handle delete on POST
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf']??'')){
    flash_set('Security token invalid. Try again.');
    header('Location: /dashboard.php'); exit;
  }

  if($isAdmin){
    $st=$mysqli->prepare("DELETE FROM trades WHERE id=?");
    $st->bind_param('i',$tradeId);
  } else {
    $st=$mysqli->prepare("DELETE FROM trades WHERE id=? AND user_id=?");
    $st->bind_param('ii',$tradeId,$userId);
  }
  $st->execute();
  $ok=$st->affected_rows>0;
  $st->close();
  flash_set($ok?'Trade deleted.':'Failed or not allowed.');
  header('Location: /dashboard.php'); exit;
}

// fetch trade for confirmation (symbol/date/outcome only if columns exist)
$cols=[];
if($res=$mysqli->query("SHOW COLUMNS FROM trades")){ while($r=$res->fetch_assoc()) $cols[strtolower($r['Field'])]=true; $res->close(); }
function pick($cols,$cands,$fallback="''"){ foreach($cands as $c){ if(!empty($cols[strtolower($c)])) return "`$c`"; } return $fallback; }
$sql="
SELECT id,user_id,
".pick($cols,['symbol'],'\'\'')." AS symbol,
".pick($cols,['entry_date','created_at'],'NULL')." AS entry_date,
".pick($cols,['outcome'],'\'\'')." AS outcome,
".pick($cols,['pl_percent'],'0')." AS pl_percent
FROM trades WHERE id=? LIMIT 1";
$st=$mysqli->prepare($sql);
$st->bind_param('i',$tradeId);
$st->execute();
$trade=$st->get_result()->fetch_assoc();
$st->close();

if(!$trade){ flash_set('Trade not found.'); header('Location: /dashboard.php'); exit; }
if(!$isAdmin && (int)$trade['user_id']!==$userId){ flash_set('Not allowed.'); header('Location: /dashboard.php'); exit; }

include __DIR__.'/header.php'; $msg=flash_pop();
?>
<div class="container" style="max-width:700px;margin:24px auto;padding:0 16px">
  <?php if($msg): ?><div style="background:#fff7ed;border:1px solid #fb923c;color:#7c2d12;padding:10px;border-radius:8px;margin-bottom:12px;font-weight:600"><?=h($msg)?></div><?php endif; ?>

  <div style="background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08);padding:20px">
    <h2>Confirm Delete</h2>
    <p style="color:#555">Aap is trade ko delete karne wale ho. Ye action irreversible hai.</p>
    <div style="background:#f9fafb;padding:10px 14px;border-radius:8px;margin-bottom:16px">
      <div><strong>ID:</strong> <?=h($trade['id'])?></div>
      <div><strong>Symbol:</strong> <?=h($trade['symbol']??'')?></div>
      <div><strong>Entry:</strong> <?=h($trade['entry_date']??'')?></div>
      <div><strong>Outcome:</strong> <?=h($trade['outcome']??'')?></div>
      <div><strong>P/L%:</strong> <?=number_format((float)($trade['pl_percent']??0),1)?></div>
    </div>
    <form method="post" onsubmit="return confirm('Pakka delete karna hai?');">
      <input type="hidden" name="id" value="<?=h($trade['id'])?>">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <button type="submit" style="background:#ef4444;color:#fff;border:0;border-radius:10px;padding:10px 14px;font-weight:800;cursor:pointer">🗑 Delete Trade</button>
      <a href="/dashboard.php" style="margin-left:8px;background:#f1f5f9;border-radius:10px;padding:10px 14px;text-decoration:none;font-weight:700;color:#111">Cancel</a>
    </form>
  </div>
</div>
<?php include __DIR__.'/footer.php'; ?>