<?php
// admin/audit_log.php — Admin Audit Log with API Integration
require_once __DIR__ . '/../includes/bootstrap.php';
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Admins only'); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function t($s){ return trim((string)$s); }
function flash($m=null){ if($m!==null){ $_SESSION['flash']=$m; } else { $m=$_SESSION['flash']??''; unset($_SESSION['flash']); return $m; } }

$VERSION='v1.0';
$flash = flash();

// 🔁 API Integration: replaced direct DB with /api/admin/audit_log.php and /api/admin/agent/logs.php

// Handle tab switching
$tab = strtolower($_GET['tab'] ?? 'audit');
if (!in_array($tab,['audit','agent'],true)) $tab='audit';

// Get filter parameters for API calls
$filters = [
    'limit' => min(100, max(1, (int)($_GET['limit'] ?? 20))),
    'offset' => max(0, (int)($_GET['offset'] ?? 0)),
    'event_type' => t($_GET['event_type'] ?? ''),
    'user_id' => t($_GET['user_id'] ?? ''),
    'q' => t($_GET['q'] ?? ''),
    'since' => t($_GET['since'] ?? ''),
    'until' => t($_GET['until'] ?? '')
];

// Function to make API calls
function callAdminApi($endpoint, $filters = []) {
    $url = $endpoint . '?' . http_build_query(array_filter($filters, function($v) { return $v !== '' && $v !== null; }));
    
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Cookie: ' . $_SERVER['HTTP_COOKIE'] ?? '',
                'X-Requested-With: XMLHttpRequest'
            ],
            'ignore_errors' => true
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['error' => 'Failed to connect to API'];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON response'];
    }
    
    return $data;
}

// Load data from APIs
$apiData = [];
$apiError = null;

try {
    if ($tab === 'audit') {
        $apiData = callAdminApi('/api/admin/audit_log.php', $filters);
    } elseif ($tab === 'agent') {
        $apiData = callAdminApi('/api/admin/agent/logs.php', $filters);
    }
} catch (Exception $e) {
    $apiError = 'Failed to load data: ' . $e->getMessage();
}

// Handle pagination parameters
$currentPage = ($filters['offset'] / $filters['limit']) + 1;
$totalItems = $apiData['data']['meta']['total'] ?? 0;
$totalPages = ceil($totalItems / $filters['limit']);

// Process API response data
$events = $apiData['data']['rows'] ?? [];
$meta = $apiData['data']['meta'] ?? [];

// 🔁 API Integration: All data loaded from API endpoints above

$title="Admin • Audit Log {$VERSION}";
include __DIR__ . '/../header.php';
?>
<div style="max-width:1400px;margin:22px auto;padding:0 16px">
  <?php if($flash): ?>
    <div style="background:#ecfdf5;border:1px solid #10b98133;padding:10px;margin-bottom:12px;border-radius:10px;color:#065f46;font-weight:700"><?=h($flash)?></div>
  <?php endif; ?>

  <?php if($apiError): ?>
    <div style="background:#fef2f2;border:1px solid #ef444433;padding:10px;margin-bottom:12px;border-radius:10px;color:#b91c1c;font-weight:700"><?=h($apiError)?></div>
  <?php endif; ?>

  <div style="display:flex;gap:8px;margin-bottom:14px">
    <?php
      $tabs=['audit'=>'System Audit Log','agent'=>'Agent Activity Log'];
    foreach($tabs as $k=>$v){
      $active = ($tab===$k) ? 'background:#5a2bd9;color:#fff;font-weight:800;border-color:transparent' : '';
      echo '<a href="?tab='.$k.'" style="padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;text-decoration:none;'.$active.'">'.$v.'</a>';
    }?>
    <div style="margin-left:auto;opacity:.6;font-size:12px">Build: <?=$VERSION?> · API Integration Active</div>
  </div>

  <!-- Filter Form -->
  <form method="get" style="margin-bottom:16px;padding:16px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0">
    <input type="hidden" name="tab" value="<?=$tab?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;align-items:end">
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">Search Query</label>
        <input type="text" name="q" value="<?=h($filters['q'])?>" placeholder="Search events..." style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
      </div>
      <?php if($tab === 'audit'): ?>
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">Event Type</label>
        <input type="text" name="event_type" value="<?=h($filters['event_type'])?>" placeholder="login, trade, etc." style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
      </div>
      <?php endif; ?>
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">User ID</label>
        <input type="number" name="user_id" value="<?=h($filters['user_id'])?>" placeholder="User ID" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
      </div>
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">From Date</label>
        <input type="date" name="since" value="<?=h($filters['since'])?>" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
      </div>
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">To Date</label>
        <input type="date" name="until" value="<?=h($filters['until'])?>" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
      </div>
      <div>
        <label style="display:block;font-size:12px;color:#64748b;margin-bottom:4px">Per Page</label>
        <select name="limit" style="width:100%;padding:8px;border:1px solid #e2e8f0;border-radius:8px">
          <?php foreach([20,50,100] as $limit): ?>
            <option value="<?=$limit?>" <?=$filters['limit']==$limit?'selected':''?>><?=$limit?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="margin-top:12px;display:flex;gap:8px">
      <button type="submit" style="background:#5a2bd9;color:#fff;border:0;border-radius:8px;padding:8px 16px;font-weight:700;cursor:pointer">Filter</button>
      <a href="?tab=<?=$tab?>" style="padding:8px 16px;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#64748b">Clear</a>
    </div>
  </form>

  <!-- Results Table -->
  <div style="overflow:auto;background:#fff;border-radius:12px;box-shadow:0 10px 28px rgba(16,24,40,.08)">
    <table style="width:100%;border-collapse:collapse;font-size:14px">
      <thead>
        <tr style="background:#f8fafc">
          <?php if($tab === 'audit'): ?>
            <th style="padding:10px;text-align:left">ID</th>
            <th style="padding:10px;text-align:left">Event Type</th>
            <th style="padding:10px;text-align:left">Category</th>
            <th style="padding:10px;text-align:left">User/Admin</th>
            <th style="padding:10px;text-align:left">Description</th>
            <th style="padding:10px;text-align:left">Severity</th>
            <th style="padding:10px;text-align:left">Status</th>
            <th style="padding:10px;text-align:left">Timestamp</th>
          <?php else: ?>
            <th style="padding:10px;text-align:left">ID</th>
            <th style="padding:10px;text-align:left">User ID</th>
            <th style="padding:10px;text-align:left">Event</th>
            <th style="padding:10px;text-align:left">Meta</th>
            <th style="padding:10px;text-align:left">Timestamp</th>
          <?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($events)): ?>
          <tr><td colspan="<?=$tab === 'audit' ? 8 : 5?>" style="padding:12px;color:#666;text-align:center">No events found.</td></tr>
        <?php else: foreach($events as $event): ?>
          <tr style="border-top:1px solid #eef2f7">
            <?php if($tab === 'audit'): ?>
              <td style="padding:10px"><?=(int)$event['id']?></td>
              <td style="padding:10px">
                <span style="background:#e0e7ff;color:#3730a3;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:600"><?=h($event['event_type'])?></span>
              </td>
              <td style="padding:10px;color:#64748b"><?=h($event['event_category'] ?? '—')?></td>
              <td style="padding:10px">
                <?php if($event['admin_id']): ?>
                  <span style="color:#059669">Admin #<?=(int)$event['admin_id']?></span>
                <?php elseif($event['user_id']): ?>
                  <span style="color:#0891b2">User #<?=(int)$event['user_id']?></span>
                <?php else: ?>
                  <span style="color:#6b7280">System</span>
                <?php endif; ?>
              </td>
              <td style="padding:10px;max-width:400px"><?=h($event['description'])?></td>
              <td style="padding:10px">
                <?php 
                $severity = strtolower($event['severity'] ?? 'info');
                $colors = ['low' => '#10b981', 'medium' => '#f59e0b', 'high' => '#ef4444', 'critical' => '#dc2626'];
                $color = $colors[$severity] ?? '#6b7280';
                ?>
                <span style="color:<?=$color?>;font-weight:600;text-transform:uppercase;font-size:11px"><?=h($severity)?></span>
              </td>
              <td style="padding:10px">
                <?php 
                $status = strtolower($event['status'] ?? 'completed');
                $statusColors = ['success' => '#10b981', 'pending' => '#f59e0b', 'error' => '#ef4444'];
                $statusColor = $statusColors[$status] ?? '#6b7280';
                ?>
                <span style="color:<?=$statusColor?>;font-weight:600"><?=h($status)?></span>
              </td>
              <td style="padding:10px;color:#64748b;font-size:12px"><?=h($event['created_at'])?></td>
            <?php else: ?>
              <td style="padding:10px"><?=(int)$event['id']?></td>
              <td style="padding:10px"><?=(int)$event['user_id']?></td>
              <td style="padding:10px">
                <code style="background:#f1f5f9;padding:2px 6px;border-radius:4px;font-size:12px"><?=h($event['event'])?></code>
              </td>
              <td style="padding:10px;max-width:300px">
                <?php if($event['meta']): ?>
                  <details style="cursor:pointer">
                    <summary style="color:#5a2bd9">View Details</summary>
                    <pre style="background:#f8fafc;padding:8px;border-radius:4px;margin-top:4px;font-size:12px;overflow:auto"><?=h(json_encode($event['meta'], JSON_PRETTY_PRINT))?></pre>
                  </details>
                <?php else: ?>
                  <span style="color:#6b7280">—</span>
                <?php endif; ?>
              </td>
              <td style="padding:10px;color:#64748b;font-size:12px"><?=h($event['created_at'])?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($totalPages > 1): ?>
    <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center">
      <div style="color:#64748b;font-size:14px">
        Showing <?=($filters['offset'] + 1)?> to <?=min($filters['offset'] + $filters['limit'], $totalItems)?> of <?=$totalItems?> results
      </div>
      <div style="display:flex;gap:4px">
        <?php 
        $queryParams = array_filter([
            'tab' => $tab,
            'q' => $filters['q'],
            'event_type' => $filters['event_type'],
            'user_id' => $filters['user_id'],
            'since' => $filters['since'],
            'until' => $filters['until'],
            'limit' => $filters['limit']
        ], function($v) { return $v !== '' && $v !== null; });
        
        // Previous button
        if ($currentPage > 1) {
            $prevParams = $queryParams;
            $prevParams['offset'] = ($currentPage - 2) * $filters['limit'];
            echo '<a href="?' . http_build_query($prevParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">‹ Previous</a>';
        }
        
        // Page numbers
        for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
            $pageParams = $queryParams;
            $pageParams['offset'] = ($i - 1) * $filters['limit'];
            $isActive = ($i === $currentPage);
            $style = $isActive 
                ? 'background:#5a2bd9;color:#fff;border-color:transparent' 
                : 'background:#fff;color:#5a2bd9;border-color:#e2e8f0';
            echo '<a href="?' . http_build_query($pageParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;' . $style . '">' . $i . '</a>';
        }
        
        // Next button
        if ($currentPage < $totalPages) {
            $nextParams = $queryParams;
            $nextParams['offset'] = $currentPage * $filters['limit'];
            echo '<a href="?' . http_build_query($nextParams) . '" style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#5a2bd9">Next ›</a>';
        }
        ?>
      </div>
    </div>
  <?php endif; ?>

  <div style="opacity:.5;font-size:12px;margin-top:10px;text-align:right">Audit Log <?=$VERSION?> · API Powered</div>
</div>

<script>
// Add loading states
document.addEventListener('DOMContentLoaded', function() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Loading...';
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Filter';
                }, 3000);
            }
        });
    });
    
    // Handle details toggle with smooth animation
    const details = document.querySelectorAll('details');
    details.forEach(detail => {
        detail.addEventListener('toggle', function() {
            if (this.open) {
                this.querySelector('pre').style.maxHeight = this.querySelector('pre').scrollHeight + 'px';
            } else {
                this.querySelector('pre').style.maxHeight = '0';
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>