<?php
/**
 * Phase-3 Litmus Database Sanity Check
 * Verifies presence of all canonical tables
 */

require_once 'config.php';
require_once 'includes/http/json.php';

header('Content-Type: application/json');

$response = [
    'timestamp' => date('c'),
    'test' => 'database_sanity_check',
    'canonical_tables' => [
        'users', 'trades', 'mtm_models', 'mtm_tasks', 'mtm_enrollments',
        'audit_logs', 'audit_event_types', 'audit_retention_policies', 
        'rate_limits', 'idempotency_keys', 'agent_logs'
    ],
    'results' => [],
    'missing_tables' => [],
    'summary' => [
        'total_checked' => 0,
        'present' => 0,
        'missing' => 0,
        'status' => 'unknown'
    ]
];

try {
    $pdo = new PDO("mysql:host=" . $dbHost . ";dbname=" . $dbName, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    foreach ($response['canonical_tables'] as $table) {
        $response['results'][$table] = [
            'exists' => false,
            'error' => null
        ];
        
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            $response['results'][$table]['exists'] = (bool)$exists;
            
            if ($exists) {
                $response['summary']['present']++;
            } else {
                $response['missing_tables'][] = $table;
            }
            
        } catch (Exception $e) {
            $response['results'][$table]['error'] = $e->getMessage();
            $response['missing_tables'][] = $table;
        }
        
        $response['summary']['total_checked']++;
    }
    
    $response['summary']['missing'] = count($response['missing_tables']);
    $response['summary']['status'] = ($response['summary']['missing'] === 0) ? 'PASS' : 'FAIL';
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    $response['summary']['status'] = 'ERROR';
}

echo json_encode($response, JSON_PRETTY_PRINT);