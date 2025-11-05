<?php
// admin/view_profile_fields.php — Read-only profile fields viewer
require_once __DIR__ . '/../config.php';
if (empty($_SESSION['is_admin'])) { header('HTTP/1.1 403 Forbidden'); exit('Access denied'); }

$profile_fields = [];
$file_path = __DIR__ . '/../profile_fields.php';

if (file_exists($file_path)) {
    $profile_fields = include $file_path;
} else {
    // Optional: Create default if missing
    $profile_fields = [
        'full_name' => [
            'label' => 'Full Name',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Enter your full name'
        ],
        'phone' => [
            'label' => 'Phone Number',
            'type' => 'tel',
            'required' => true,
            'placeholder' => '+91 XXXXX XXXXX'
        ],
        'country' => [
            'label' => 'Country',
            'type' => 'select',
            'required' => true,
            'options' => [
                '' => 'Select Country',
                'IN' => 'India',
                'Other' => 'Other'
            ]
        ]
    ];
}

include __DIR__ . '/../header.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Profile Fields — Shaikhoology Admin</title>
<style>
  body {
    font-family: Inter, -apple-system, BlinkMacSystemFont, sans-serif;
    background: #f8fafc;
    color: #1e293b;
    margin: 0;
    padding: 0;
  }
  .container {
    max-width: 900px;
    margin: 24px auto;
    padding: 0 16px;
  }
  h1 {
    font-size: 24px;
    font-weight: 800;
    margin: 0 0 24px;
    color: #0f172a;
  }
  .card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    padding: 20px;
    margin-bottom: 24px;
  }
  .field-item {
    padding: 14px 0;
    border-bottom: 1px solid #f1f5f9;
  }
  .field-item:last-child {
    border-bottom: none;
  }
  .field-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
  }
  .field-name {
    font-weight: 700;
    font-size: 16px;
    color: #0f172a;
  }
  .field-required {
    background: #dbeafe;
    color: #1d4ed8;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
  }
  .field-meta {
    display: flex;
    gap: 12px;
    font-size: 13px;
    color: #64748b;
    margin: 4px 0;
  }
  .field-type {
    text-transform: uppercase;
    font-weight: 600;
  }
  .field-placeholder {
    color: #94a3b8;
    font-style: italic;
  }
  .options-list {
    margin-top: 8px;
    padding-left: 20px;
    font-size: 13px;
    color: #475569;
  }
  .note {
    background: #fffbeb;
    border-left: 4px solid #d97706;
    padding: 12px;
    border-radius: 0 8px 8px 0;
    margin-top: 24px;
    font-size: 14px;
  }
  .back-link {
    display: inline-block;
    margin-top: 16px;
    color: #4f46e5;
    text-decoration: none;
    font-weight: 600;
  }
  .back-link:hover {
    text-decoration: underline;
  }
</style>
</head>
<body>
  <div class="container">
    <h1>📝 Profile Fields Configuration</h1>

    <?php if (empty($profile_fields)): ?>
      <div class="card">
        <p>No profile fields configured.</p>
      </div>
    <?php else: ?>
      <div class="card">
        <?php foreach ($profile_fields as $field => $config): ?>
          <div class="field-item">
            <div class="field-header">
              <span class="field-name"><?= htmlspecialchars($config['label']) ?></span>
              <?php if (!empty($config['required'])): ?>
                <span class="field-required">Required</span>
              <?php endif; ?>
            </div>
            <div class="field-meta">
              <span class="field-type"><?= htmlspecialchars($config['type']) ?></span>
              <?php if (!empty($config['placeholder'])): ?>
                <span class="field-placeholder"><?= htmlspecialchars($config['placeholder']) ?></span>
              <?php endif; ?>
            </div>
            <?php if (!empty($config['options']) && is_array($config['options'])): ?>
              <div class="options-list">
                <strong>Options:</strong>
                <?php foreach ($config['options'] as $value => $label): ?>
                  <div><?= htmlspecialchars($value) ?>: <?= htmlspecialchars($label) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="note">
      <strong>ℹ️ Note:</strong> To edit these fields, you must manually update the file:<br>
      <code>/public_html/profile_fields.php</code><br>
      After adding new fields, ensure the corresponding database columns exist in the <code>users</code> table.
    </div>

    <a href="admin_dashboard.php" class="back-link">← Back to Admin Dashboard</a>
  </div>
</body>
</html>
<?php include __DIR__ . '/../footer.php'; ?>