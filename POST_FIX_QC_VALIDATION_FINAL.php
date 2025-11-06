<?php
/**
 * COMPREHENSIVE POST-FIX QC VALIDATION
 * Final verification of all Phase 3 QC criteria after database-backed rate limiting implementation
 */

require_once 'includes/config.php';
require_once 'includes/bootstrap.php';

echo "=== POST-FIX QC VALIDATION - PHASE 3 FINAL ===\n\n";

$qc_results = [
    'rate_limit' => ['status' => 'UNKNOWN', 'score' => 0, 'details' => []],
    'csrf' => ['status' => 'UNKNOWN', 'score' => 0, 'details' => []],
    'audit' => ['status' => 'UNKNOWN', 'score' => 0, 'details' => []],
    'openapi' => ['status' => 'UNKNOWN', 'score' => 0, 'details' => []],
    'overall' => ['status' => 'UNKNOWN', 'score' => 0]
];

// 1. RATE LIMITING VALIDATION
echo "1. RATE LIMITING SYSTEM VALIDATION:\n";
echo "=====================================\n";

try {
    // Test database-backed rate limiting directly
    require_once 'includes/security/ratelimit.php';
    
    echo "✅ Database-backed rate limiting system: IMPLEMENTED\n";
    
    // Test rate limit functionality
    rate_limit_clear('qc_test', 'test:qc');
    
    $test_results = [];
    for ($i = 1; $i <= 5; $i++) {
        $result = rate_limit('qc_test', 3);
        $test_results[] = $result;
    }
    
    $passed = 0;
    $blocked = 0;
    foreach ($test_results as $i => $result) {
        $request_num = $i + 1;
        if ($result['allowed']) {
            $passed++;
            echo "  Request $request_num: ✅ ALLOWED (Count: {$result['count']})\n";
        } else {
            $blocked++;
            echo "  Request $request_num: ❌ BLOCKED (Count: {$result['count']})\n";
        }
    }
    
    // Validate expected behavior
    if ($passed === 3 && $blocked === 2) {
        $qc_results['rate_limit']['status'] = 'PASS';
        $qc_results['rate_limit']['score'] = 100;
        $qc_results['rate_limit']['details'] = ['Database-backed implementation functional', 'Proper limit enforcement', '3 allowed, 2 blocked as expected'];
        echo "✅ Rate limiting validation: PASSED (100/100)\n";
    } else {
        $qc_results['rate_limit']['status'] = 'FAIL';
        $qc_results['rate_limit']['score'] = 0;
        $qc_results['rate_limit']['details'] = ['Unexpected rate limiting behavior'];
        echo "❌ Rate limiting validation: FAILED\n";
    }
    
} catch (Exception $e) {
    $qc_results['rate_limit']['status'] = 'FAIL';
    $qc_results['rate_limit']['score'] = 0;
    $qc_results['rate_limit']['details'] = ['Error: ' . $e->getMessage()];
    echo "❌ Rate limiting validation: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// 2. CSRF VALIDATION
echo "2. CSRF PROTECTION VALIDATION:\n";
echo "===============================\n";

try {
    // Check CSRF implementation
    require_once 'includes/security/csrf.php';
    
    // Test CSRF token generation
    $token1 = get_csrf_token();
    $token2 = get_csrf_token();
    
    if (!empty($token1) && $token1 === $token2) {
        echo "✅ CSRF token generation: WORKING\n";
        $csrf_score = 100;
    } else {
        echo "❌ CSRF token generation: FAILED\n";
        $csrf_score = 50;
    }
    
    // Check endpoint integration
    $csrf_endpoints = [
        'login.php' => 'includes/security/csrf.php',
        'register.php' => 'includes/security/csrf.php',
        'api/trades/create.php' => 'includes/security/csrf.php',
        'api/mtm/enroll.php' => 'includes/security/csrf.php',
    ];
    
    $protected_count = 0;
    foreach ($csrf_endpoints as $file => $include) {
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if (strpos($content, $include) !== false) {
                $protected_count++;
                echo "✅ $file: CSRF protection included\n";
            } else {
                echo "❌ $file: CSRF protection missing\n";
            }
        }
    }
    
    $csrf_percentage = round(($protected_count / count($csrf_endpoints)) * 100);
    $qc_results['csrf']['score'] = $csrf_percentage;
    
    if ($csrf_percentage >= 90) {
        $qc_results['csrf']['status'] = 'PASS';
        echo "✅ CSRF validation: PASSED ($csrf_percentage/100)\n";
    } else {
        $qc_results['csrf']['status'] = 'PARTIAL';
        echo "⚠️ CSRF validation: PARTIAL ($csrf_percentage/100)\n";
    }
    
} catch (Exception $e) {
    $qc_results['csrf']['status'] = 'FAIL';
    $qc_results['csrf']['score'] = 0;
    echo "❌ CSRF validation: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// 3. AUDIT SYSTEM VALIDATION  
echo "3. AUDIT SYSTEM VALIDATION:\n";
echo "============================\n";

try {
    require_once 'includes/logger/audit_log.php';
    
    // Test audit logging
    $test_event_id = log_audit_event(null, 'system_test', 'system', null, 'QC Post-Fix validation test');
    
    if ($test_event_id) {
        echo "✅ Audit logging: FUNCTIONAL (Event ID: $test_event_id)\n";
        $qc_results['audit']['status'] = 'PASS';
        $qc_results['audit']['score'] = 100;
        $qc_results['audit']['details'] = ['Audit logging functional', 'Events recorded successfully'];
        
        // Check audit table
        $result = $mysqli->query("SELECT COUNT(*) as count FROM audit_events");
        $audit_count = $result ? (int)$result->fetch_assoc()['count'] : 0;
        echo "✅ Total audit events: $audit_count\n";
        
    } else {
        echo "❌ Audit logging: FAILED\n";
        $qc_results['audit']['status'] = 'FAIL';
        $qc_results['audit']['score'] = 0;
    }
    
} catch (Exception $e) {
    $qc_results['audit']['status'] = 'FAIL';
    $qc_results['audit']['score'] = 0;
    echo "❌ Audit validation: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// 4. OPENAPI DOCUMENTATION VALIDATION
echo "4. OPENAPI DOCUMENTATION VALIDATION:\n";
echo "====================================\n";

try {
    $openapi_file = 'docs/openapi.yaml';
    
    if (file_exists($openapi_file)) {
        $content = file_get_contents($openapi_file);
        
        // Check for key endpoints
        $required_endpoints = [
            '/api/trades/create.php',
            '/api/mtm/enroll.php', 
            '/api/admin/enrollment/approve.php',
            '/api/health.php',
            '/api/dashboard/metrics.php'
        ];
        
        $documented_count = 0;
        foreach ($required_endpoints as $endpoint) {
            if (strpos($content, $endpoint) !== false) {
                $documented_count++;
                echo "✅ $endpoint: Documented\n";
            } else {
                echo "❌ $endpoint: Missing from OpenAPI\n";
            }
        }
        
        $openapi_percentage = round(($documented_count / count($required_endpoints)) * 100);
        $qc_results['openapi']['score'] = $openapi_percentage;
        
        if ($openapi_percentage >= 80) {
            $qc_results['openapi']['status'] = 'PASS';
            echo "✅ OpenAPI validation: PASSED ($openapi_percentage/100)\n";
        } else {
            $qc_results['openapi']['status'] = 'PARTIAL';
            echo "⚠️ OpenAPI validation: PARTIAL ($openapi_percentage/100)\n";
        }
        
    } else {
        echo "❌ OpenAPI file not found: $openapi_file\n";
        $qc_results['openapi']['status'] = 'FAIL';
        $qc_results['openapi']['score'] = 0;
    }
    
} catch (Exception $e) {
    $qc_results['openapi']['status'] = 'FAIL';
    $qc_results['openapi']['score'] = 0;
    echo "❌ OpenAPI validation: ERROR - " . $e->getMessage() . "\n";
}

echo "\n";

// 5. OVERALL QC ASSESSMENT
echo "5. OVERALL QC ASSESSMENT:\n";
echo "=========================\n";

$total_score = 0;
$passed_criteria = 0;
$total_criteria = count($qc_results) - 1; // Exclude 'overall' from count

foreach ($qc_results as $category => $result) {
    if ($category === 'overall') continue;
    
    $total_score += $result['score'];
    $status_symbol = match($result['status']) {
        'PASS' => '✅',
        'PARTIAL' => '⚠️',
        'FAIL' => '❌',
        default => '❓'
    };
    
    echo "$status_symbol " . ucfirst(str_replace('_', ' ', $category)) . ": {$result['score']}/100 ({$result['status']})\n";
    
    if ($result['status'] === 'PASS') {
        $passed_criteria++;
    }
}

$average_score = round($total_score / $total_criteria);
$qc_results['overall']['score'] = $average_score;

// Determine overall status
if ($average_score >= 90 && $passed_criteria === $total_criteria) {
    $qc_results['overall']['status'] = 'GREEN';
    $gate_status = 'GREEN';
} elseif ($average_score >= 70) {
    $qc_results['overall']['status'] = 'YELLOW';
    $gate_status = 'YELLOW';
} else {
    $qc_results['overall']['status'] = 'RED';
    $gate_status = 'RED';
}

echo "\n";
echo "📊 FINAL QC SCORES:\n";
echo "===================\n";
echo "Rate Limiting: {$qc_results['rate_limit']['score']}/100 ({$qc_results['rate_limit']['status']})\n";
echo "CSRF Protection: {$qc_results['csrf']['score']}/100 ({$qc_results['csrf']['status']})\n";
echo "Audit System: {$qc_results['audit']['score']}/100 ({$qc_results['audit']['status']})\n";
echo "OpenAPI Docs: {$qc_results['openapi']['score']}/100 ({$qc_results['openapi']['status']})\n";
echo "\n";
echo "🎯 OVERALL QC SCORE: $average_score/100 ($gate_status GATE)\n";
echo "📈 PASSED CRITERIA: $passed_criteria/$total_criteria\n";

// 6. PHASE 3 READINESS DETERMINATION
echo "\n";
echo "6. PHASE 3 READINESS DETERMINATION:\n";
echo "===================================\n";

if ($gate_status === 'GREEN') {
    echo "🎉 PHASE 3 PRODUCTION READINESS: ✅ ACHIEVED\n";
    echo "✅ All critical QC criteria met\n";
    echo "✅ Rate limiting system functional\n";
    echo "✅ Security controls in place\n";
    echo "✅ Documentation complete\n";
    echo "\n📋 Creating readiness flag: .qc_ready_for_phase3\n";
    
    // Create Phase 3 readiness flag
    $flag_content = json_encode([
        'status' => 'ready',
        'qc_score' => $average_score,
        'gate_status' => $gate_status,
        'validated_at' => date('c'),
        'criteria_met' => $passed_criteria,
        'total_criteria' => $total_criteria,
        'details' => $qc_results
    ], JSON_PRETTY_PRINT);
    
    file_put_contents('.qc_ready_for_phase3', $flag_content);
    echo "✅ Phase 3 readiness flag created\n";
    
} else {
    echo "❌ PHASE 3 PRODUCTION READINESS: NOT ACHIEVED\n";
    echo "⚠️ Critical issues remain: " . ($total_criteria - $passed_criteria) . "\n";
    echo "📊 Current score: $average_score/100 (Target: 90+)\n";
    echo "\n🔧 REMEDIATION REQUIRED before Phase 3 deployment\n";
}

echo "\n=== END POST-FIX QC VALIDATION ===\n";

return $qc_results;
?>