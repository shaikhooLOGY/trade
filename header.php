<?php
require_once __DIR__ . '/includes/env.php';   // defines APP_ENV + loads .env
require_once __DIR__ . '/config.php';         // connects DB, defines guards
require_once __DIR__ . '/includes/bootstrap.php'; // session start, headers, csrf
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Shaikhoology — Trading Psychology</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bar:#5a2bd9;
    --pillText:#5a2bd9;
  }
  body{margin:0;font-family:Inter,system-ui,Arial,sans-serif;background:#f6f7fb;color:#111}
  
  /* MAIN CONTAINER - ALIGN WITH CONTENT WIDTH */
  main{
    max-width:100vw;
    margin:0 auto;
    padding:0;
    overflow-x:hidden;
    box-sizing:border-box;
  }

  /* --- Topbar --- */
  .topbar{
    background:#000;
    color:#fff;
    padding:16px;
    max-width:100vw;
    margin:0 auto;
    box-sizing:border-box;
    overflow-x:hidden;
  }
  .topbar-inner{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    text-align:center;
    flex-wrap:nowrap;
    max-width:100%;
    margin:0 auto;
  }
  .brand-logo{
    height:44px;
    width:44px;
    border-radius:50%;
    object-fit:cover;
    flex-shrink:0;
  }
  .brand-text h1{
    margin:0;
    font-size:26px;
    letter-spacing:-.3px;
    line-height:1.1;
  }
  .brand-sub{
    opacity:.8;
    font-size:13px;
    margin-top:2px;
  }

  @media (max-width:560px){
    .topbar{padding:12px 8px;}
    .topbar-inner{gap:8px;}
    .brand-logo{height:34px;width:34px;}
    .brand-text h1{font-size:18px;}
    .brand-sub{font-size:10px;}
  }

  /* --- Subnav --- */
  .subnav{
    background:var(--bar);
    color:#fff;
    padding:10px 16px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    max-width:100vw;
    margin:0 auto;
    box-sizing:border-box;
    overflow-x:auto;
    scrollbar-width:none;
    -ms-overflow-style:none;
  }
  .subnav::-webkit-scrollbar{display:none;}
  
  .subnav .left,
  .subnav .right{
    display:flex;
    align-items:center;
    gap:6px;
    flex-shrink:0;
  }
  
  .subnav a{
    color:#fff;
    text-decoration:none;
    font-weight:700;
    margin-right:6px;
    padding:8px 12px;
    border-radius:999px;
    transition:background .15s ease,color .15s ease,box-shadow .15s ease,border-color .15s ease;
    border:1px solid transparent;
    white-space:nowrap;
    flex-shrink:0;
  }
  .subnav a:hover{background:rgba(255,255,255,0.12)}
  .subnav a.active{
    background:#ffffff;
    color:var(--pillText);
    border-color:rgba(255,255,255,0.85);
    box-shadow:inset 0 1px 0 rgba(255,255,255,0.75),0 2px 6px rgba(0,0,0,0.18);
  }
  .username{font-weight:600;white-space:nowrap;color:#fff;margin-right:8px;flex-shrink:0}
</style>
</head>
<body>

<!-- Topbar with logo + title -->
<div class="topbar">
  <div class="topbar-inner">
    <img src="/img/logo.png" alt="Logo" class="brand-logo">
    <div class="brand-text">
     <h1 id="ustaad-header" style="margin:0;">
  <span class="brand">SHAIKH</span>
  <span class="oo" aria-hidden="true">
    <span class="eye left"><span class="dot"></span></span>
    <span class="eye right"><span class="dot"></span></span>
  </span>
  <span class="brand">LOGY</span>
</h1>

<style>
  /* ---- Compact title stays same, eyes bigger + faster + rotating glow ---- */
  #ustaad-header{
    display:inline-flex;align-items:center;gap:2px;
    cursor:pointer;color:#fff;font-weight:800;
    text-shadow:0 0 8px var(--glow,#22d3ee);
    transition:text-shadow .2s ease,transform .12s ease;
  }
  #ustaad-header:hover{transform:scale(1.03);}
  #ustaad-header:active{transform:scale(.98);}

  /* Eyes wrapper – tight spacing with text */
  #ustaad-header .oo{
    position:relative;width:40px;height:26px;
    display:inline-block;margin:0 3px;
  }

  /* Eyes – larger than letters, faster default blink */
  #ustaad-header .eye{
    position:absolute;top:2px;
    width:18px;height:18px;
    border-radius:50%;background:#fff;border:2px solid #000;overflow:hidden;
    transform-origin:center;
    animation:blinkMed 3s infinite;
    box-shadow:0 0 10px var(--glow,#22d3ee);
  }
  #ustaad-header .eye.left{left:1px;transform:scale(.92);}
  #ustaad-header .eye.right{right:1px;transform:scale(1.08);animation-delay:.6s;}

  /* pupil as .dot */
  .dot{
    position:absolute;left:6px;top:6px;
    width:5px;height:5px;background:#000;border-radius:50%;
    transition:transform .4s ease;
  }

  #ustaad-header:hover .dot{transform:translateY(-1px);}

  /* Hover → even faster; Active → fastest */
  #ustaad-header:hover .eye{animation:blinkFast 1.2s infinite;}
  #ustaad-header:active .eye{animation:blinkTurbo .8s infinite;}

  @keyframes blinkMed{0%,86%,100%{transform:scaleY(1);}92%,96%{transform:scaleY(.12);}}
  @keyframes blinkFast{0%,78%,100%{transform:scaleY(1);}86%,90%{transform:scaleY(.12);}}
  @keyframes blinkTurbo{0%,70%,100%{transform:scaleY(1);}80%,84%{transform:scaleY(.12);}}
</style>

<script>
  // rotating glow colors + eyes look around
  (function(){
    const hdr=document.getElementById('ustaad-header');
    const dots=document.querySelectorAll('#ustaad-header .dot');
    const colors=['#22d3ee','#a78bfa','#f472b6','#34d399','#f59e0b','#60a5fa'];
    let ci=0, timerGlow, timerLook;

    function setGlow(c){hdr.style.setProperty('--glow',c);}
    function startGlow(){
      stopGlow();
      timerGlow=setInterval(()=>{ci=(ci+1)%colors.length;setGlow(colors[ci]);},2500);
    }
    function stopGlow(){if(timerGlow)clearInterval(timerGlow);}
    setGlow(colors[0]);startGlow();
    document.addEventListener('visibilitychange',()=>{if(document.hidden)stopGlow();else startGlow();});
    hdr.addEventListener('click',()=>{ci=(ci+1)%colors.length;setGlow(colors[ci]);});

    // --- Eye motion logic ---
    const moves=[{x:0,y:0},{x:2,y:0},{x:-2,y:0},{x:0,y:-2},{x:0,y:2},{x:2,y:2},{x:-2,y:-2}];
    let mi=0;
    function moveEyes(){
      mi=(mi+1)%moves.length;
      const m=moves[mi];
      dots.forEach(d=>{d.style.transform=`translate(${m.x}px,${m.y}px)`;});
    }
    timerLook=setInterval(moveEyes,5000);
    document.addEventListener('visibilitychange',()=>{if(document.hidden)clearInterval(timerLook);else timerLook=setInterval(moveEyes,2000);});
  })();
</script>
      <div class="brand-sub">Trading Psychology</div>
    </div>
  </div>
</div>

<?php
  $hideNav = isset($hideNav) ? (bool)$hideNav : false;
  $isLogged  = !empty($_SESSION['user_id']);
  $status    = strtolower((string)($_SESSION['status'] ?? ''));
  $emailVer  = (int)($_SESSION['email_verified'] ?? 0);
  $isAdmin   = !empty($_SESSION['is_admin']);
  $username  = $isLogged ? ($_SESSION['username'] ?? 'Member') : 'Guest';
  $isActive  = $isLogged && ($emailVer === 1) && in_array($status, ['active','approved'], true);
  $showFullNav = (!empty($_SESSION['user_id'])) && ($isActive || APP_ENV === 'local' || !empty($_SESSION['is_admin']));
  $cur = strtolower(basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)));

  function nav($href,$label,$cur,$names){
    $names=(array)$names;
    $isActive=in_array(strtolower($cur),array_map('strtolower',$names),true);
    $cls=$isActive?'active':'';
    $hHref=htmlspecialchars($href,ENT_QUOTES,'UTF-8');
    $hLbl=htmlspecialchars($label,ENT_QUOTES,'UTF-8');
    echo '<a class="'.$cls.'" href="'.$hHref.'">'.$hLbl.'</a>';
  }
?>

<?php if(!$hideNav): ?>
  <div class="subnav">
    <div class="left" style="display:flex;gap:6px">
      <?php
      nav('/mtm.php','🏠 Home',$cur,['mtm.php','index.php']);

      if ($showFullNav) {
        nav('/dashboard.php','📊 Dashboard',$cur,'dashboard.php');
        nav('/mtm_enroll.php','🎯 MTM Enroll',$cur,'mtm_enroll.php');
        nav('/trade_new.php','➕ Enter Trade',$cur,'trade_new.php');
      }

      nav('/leaderboard.php','🏆 Leaderboard',$cur,'leaderboard.php');

      if ($showFullNav && !empty($_SESSION['is_admin'])) {
        nav('/admin/admin_dashboard.php','🛠 Admin Dashboard',$cur,['admin_dashboard.php','users.php','user_profile.php']);
      }
      ?>
    </div>
    <div class="right" style="display:flex;align-items:center;gap:6px">
      <?php if($isLogged): ?>
        <span class="username"><?= htmlspecialchars($username) ?></span>
        <?php nav('/profile.php','👤 Profile',$cur,'profile.php'); ?>
        <?php nav('/logout.php','🚪 Logout',$cur,'logout.php'); ?>
      <?php else: ?>
        <?php nav('/login.php','🔑 Login',$cur,'login.php'); ?>
        <?php nav('/register.php','📝 Register',$cur,'register.php'); ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if(!empty($_SESSION['flash'])): ?>
  <div style="background:#dcfce7;border:1px solid #14532d;color:#14532d;
              padding:12px;text-align:center;font-weight:600;margin:0;">
    <?= htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
  </div>
<?php endif; ?>