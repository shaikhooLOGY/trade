<?php
require_once 'functions.php'; $user=currentUser(); if(!$user||!$user['is_admin']){ echo 'Forbidden'; exit; }
if($_SERVER['REQUEST_METHOD']==='POST'){ foreach($_POST as $k=>$v){ $stmt=$mysqli->prepare("UPDATE rules SET value_num=? WHERE key_name=?"); $stmt->bind_param('ds',$v,$k); $stmt->execute(); } $msg="Rules updated"; }
$rules=$mysqli->query("SELECT key_name,value_num FROM rules")->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html><html><head><meta charset="utf-8"><title>Admin</title></head><body>
<h2>Admin - Rules</h2><?php if(!empty($msg)) echo "<p style='color:green;'>".esc($msg)."</p>"; ?>
<form method="post"><?php foreach($rules as $r){ ?><label><?php echo esc($r['key_name']);?> <input name="<?php echo esc($r['key_name']);?>" value="<?php echo esc($r['value_num']);?>"></label><br><?php } ?><button>Save</button></form>
</body></html>
