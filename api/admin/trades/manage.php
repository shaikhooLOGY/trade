<?php
/**
 * api/admin/trades/manage.php
 *
 * Admin API - Trade Management with concerns, filtering, and administration
 * GET /api/admin/trades/manage.php?tab=concerns&status=pending&limit=20&offset=0
 * POST /api/admin/trades/manage.php for trade actions
 */

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Require admin authentication
$adminUser = require_admin_json('Admin access required');
$adminId = (int)$adminUser['id'];

try {
    global $mysqli;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle trade management actions
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? $_POST['trade_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $csrf = $_POST['csrf'] ?? '';
        
        // Validate CSRF
        if (empty($csrf) || !validate_csrf($csrf)) {
            json_fail('CSRF_VALIDATION_FAILED', 'Invalid CSRF token');
        }
        
        if ($id <= 0) {
            json_fail('VALIDATION_ERROR', 'Invalid ID provided');
        }
        
        switch ($action) {
            case 'approve_concern':
                $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$adminId} WHERE id={$id}");
                $mysqli->query("UPDATE trades t JOIN trade_concerns c ON t.id=c.trade_id
                                SET t.unlock_status='approved', t.unlock_approved_at=NOW()
                                WHERE c.id={$id}");
                json_ok([], 'Trade concern approved - trade unlocked for 24h window');
                break;
                
            case 'reject_concern':
                $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$adminId} WHERE id={$id}");
                $mysqli->query("UPDATE trades t JOIN trade_concerns c ON t.id=c.trade_id
                                SET t.unlock_status='rejected', t.unlock_approved_at=NULL
                                WHERE c.id={$id}");
                json_ok([], 'Trade concern rejected - trade locked');
                break;
                
            case 'resolve_concern':
                $mysqli->query("UPDATE trade_concerns SET resolved='yes', resolved_at=NOW(), resolved_by={$adminId} WHERE id={$id}");
                json_ok([], 'Trade concern marked as resolved');
                break;
                
            case 'force_unlock':
                $mysqli->query("UPDATE trades SET unlock_status='approved', unlock_approved_at=NOW() WHERE id={$id}");
                json_ok([], 'Trade unlocked for 24h window');
                break;
                
            case 'force_lock':
                $mysqli->query("UPDATE trades SET unlock_status='rejected', unlock_approved_at=NULL WHERE id={$id}");
                json_ok([], 'Trade locked');
                break;
                
            case 'soft_delete':
                if (empty($reason)) {
                    json_fail('VALIDATION_ERROR', 'Reason required for soft delete');
                }
                $stmt = $mysqli->prepare("UPDATE trades SET deleted_at=NOW(), deleted_by=?, deleted_by_admin=1, deleted_reason=? WHERE id=?");
                $stmt->bind_param('isi', $adminId, $reason, $id);
                $ok = $stmt->execute();
                $stmt->close();
                json_ok([], $ok ? 'Trade soft-deleted' : 'Failed to delete trade');
                break;
                
            case 'restore':
                $mysqli->query("UPDATE trades SET deleted_at=NULL, deleted_by=NULL, deleted_by_admin=0, deleted_reason=NULL WHERE id={$id}");
                json_ok([], 'Trade restored');
                break;
                
            default:
                json_fail('INVALID_ACTION', 'Unknown action: ' . $action);
        }
        
    } else {
        // GET request - retrieve trade data
        $tab = $_GET['tab'] ?? 'concerns';
        if (!in_array($tab, ['concerns', 'user_trades', 'deleted'], true)) {
            $tab = 'concerns';
        }
        
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        
        $data = [];
        
        if ($tab === 'concerns') {
            $status = strtolower($_GET['status'] ?? 'pending');
            if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
                $status = 'pending';
            }
            
            if ($status === 'pending') {
                $where = "LOWER(t.unlock_status)='pending' AND UPPER(COALESCE(t.outcome,''))<>'OPEN'";
            } elseif ($status === 'approved') {
                $where = "LOWER(t.unlock_status)='approved' AND UPPER(COALESCE(t.outcome,''))<>'OPEN'";
            } elseif ($status === 'rejected') {
                $where = "LOWER(t.unlock_status)='rejected' AND UPPER(COALESCE(t.outcome,''))<>'OPEN'";
            } else {
                $where = "UPPER(COALESCE(t.outcome,''))<>'OPEN'";
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total 
                        FROM trades t
                        LEFT JOIN trade_concerns c ON c.trade_id=t.id AND (c.resolved='' OR c.resolved IS NULL)
                        LEFT JOIN users u ON u.id=t.user_id
                        WHERE {$where}";
            $countResult = $mysqli->query($countSql);
            $total = $countResult->fetch_assoc()['total'];
            
            $sql = "SELECT
                        COALESCE(c.id, 0) AS id,
                        t.id AS trade_id,
                        t.user_id,
                        COALESCE(c.reason,'') AS reason,
                        COALESCE(c.created_at, t.entry_date) AS created_at,
                        u.name, u.email,
                        t.symbol, t.entry_date, t.exit_price,
                        COALESCE(t.outcome,'') AS outcome,
                        COALESCE(t.unlock_status,'none') AS unlock_status
                    FROM trades t
                    LEFT JOIN trade_concerns c
                           ON c.trade_id=t.id AND (c.resolved='' OR c.resolved IS NULL)
                    LEFT JOIN users u ON u.id=t.user_id
                    WHERE {$where}
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('ii', $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $concerns = [];
            while ($row = $result->fetch_assoc()) {
                $concerns[] = [
                    'id' => (int)$row['id'],
                    'trade_id' => (int)$row['trade_id'],
                    'user_id' => (int)$row['user_id'],
                    'reason' => $row['reason'],
                    'created_at' => $row['created_at'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'symbol' => $row['symbol'],
                    'entry_date' => $row['entry_date'],
                    'exit_price' => $row['exit_price'],
                    'outcome' => $row['outcome'],
                    'unlock_status' => $row['unlock_status']
                ];
            }
            $stmt->close();
            
            $data = [
                'rows' => $concerns,
                'meta' => [
                    'total' => (int)$total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($concerns)
                ]
            ];
            
        } elseif ($tab === 'user_trades') {
            $userId = (int)($_GET['user_id'] ?? 0);
            $status = strtolower($_GET['status'] ?? 'all');
            if (!in_array($status, ['all', 'open', 'closed', 'unlocked', 'locked', 'deleted', 'required_unlock'], true)) {
                $status = 'all';
            }
            
            $whereParts = [];
            $params = [];
            
            if ($userId > 0) {
                $whereParts[] = "t.user_id=?";
                $params[] = $userId;
            }
            
            if ($status === 'deleted') {
                $whereParts[] = "t.deleted_at IS NOT NULL";
            } else {
                $whereParts[] = "t.deleted_at IS NULL";
                if ($status === 'open') {
                    $whereParts[] = "UPPER(COALESCE(t.outcome,'OPEN'))='OPEN'";
                } elseif ($status === 'closed') {
                    $whereParts[] = "UPPER(COALESCE(t.outcome,''))<>'OPEN'";
                } elseif ($status === 'unlocked') {
                    $whereParts[] = "LOWER(t.unlock_status)='approved' AND UPPER(COALESCE(t.outcome,''))<>'OPEN'";
                } elseif ($status === 'locked') {
                    $whereParts[] = "LOWER(COALESCE(t.unlock_status,'none')) IN ('none','rejected') AND UPPER(COALESCE(t.outcome,''))<>'OPEN'";
                } elseif ($status === 'required_unlock') {
                    $whereParts[] = "UPPER(COALESCE(t.outcome,''))<>'OPEN' AND LOWER(COALESCE(t.unlock_status,'none')) NOT IN ('approved','rejected')";
                }
            }
            
            $where = implode(' AND ', $whereParts);
            if (empty($where)) $where = '1';
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total 
                        FROM trades t
                        LEFT JOIN users u ON u.id=t.user_id
                        WHERE {$where}";
            $countResult = $mysqli->query($countSql);
            $total = $countResult->fetch_assoc()['total'];
            
            $sql = "SELECT t.id,t.user_id,COALESCE(t.symbol,'') symbol,t.entry_date,t.exit_price,
                           COALESCE(t.outcome,'') outcome, COALESCE(t.pl_percent,0) pl_percent,
                           COALESCE(t.unlock_status,'none') unlock_status, t.unlock_approved_at,
                           t.deleted_at, t.deleted_by_admin, t.deleted_reason,
                           u.name, u.email
                    FROM trades t
                    LEFT JOIN users u ON u.id=t.user_id
                    WHERE {$where}
                    ORDER BY t.id DESC
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param(str_repeat('i', count($params)), ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $trades = [];
            while ($row = $result->fetch_assoc()) {
                $trades[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'symbol' => $row['symbol'],
                    'entry_date' => $row['entry_date'],
                    'exit_price' => $row['exit_price'],
                    'outcome' => $row['outcome'],
                    'pl_percent' => (float)$row['pl_percent'],
                    'unlock_status' => $row['unlock_status'],
                    'unlock_approved_at' => $row['unlock_approved_at'],
                    'deleted_at' => $row['deleted_at'],
                    'deleted_by_admin' => (bool)$row['deleted_by_admin'],
                    'deleted_reason' => $row['deleted_reason'],
                    'name' => $row['name'],
                    'email' => $row['email']
                ];
            }
            $stmt->close();
            
            $data = [
                'rows' => $trades,
                'meta' => [
                    'total' => (int)$total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($trades)
                ]
            ];
            
        } elseif ($tab === 'deleted') {
            // Get total count
            $countSql = "SELECT COUNT(*) as total 
                        FROM trades t
                        LEFT JOIN users u ON u.id=t.user_id
                        WHERE t.deleted_at IS NOT NULL";
            $countResult = $mysqli->query($countSql);
            $total = $countResult->fetch_assoc()['total'];
            
            $sql = "SELECT t.id, t.user_id, COALESCE(u.name,u.email) user_name,
                           COALESCE(t.symbol,'') symbol, t.entry_date, t.deleted_at,
                           t.deleted_by_admin, t.deleted_reason
                    FROM trades t
                    LEFT JOIN users u ON u.id=t.user_id
                    WHERE t.deleted_at IS NOT NULL
                    ORDER BY t.deleted_at DESC
                    LIMIT ? OFFSET ?";
            
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param('ii', $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $deleted = [];
            while ($row = $result->fetch_assoc()) {
                $deleted[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (int)$row['user_id'],
                    'user_name' => $row['user_name'],
                    'symbol' => $row['symbol'],
                    'entry_date' => $row['entry_date'],
                    'deleted_at' => $row['deleted_at'],
                    'deleted_by_admin' => (bool)$row['deleted_by_admin'],
                    'deleted_reason' => $row['deleted_reason']
                ];
            }
            $stmt->close();
            
            $data = [
                'rows' => $deleted,
                'meta' => [
                    'total' => (int)$total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($deleted)
                ]
            ];
        }
        
        // Log admin access
        app_log('info', sprintf(
            'Admin accessed trade management: Tab=%s, Admin=%d, Limit=%d, Offset=%d',
            $tab, $adminId, $limit, $offset
        ));
        
        json_ok($data, 'Trade management data retrieved successfully');
    }
    
} catch (Exception $e) {
    app_log('error', 'Admin trade management error: ' . $e->getMessage());
    json_fail('SERVER_ERROR', 'Failed to process trade management request');
}