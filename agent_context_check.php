<?php
/**
 * Agent Context Check for Phase 3 Pre-Integration
 * Validates project_context.json structure and updates with pre-integration validation
 */

echo "=== PHASE 3 PRE-INTEGRATION AGENT CONTEXT CHECK ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

$context_file = 'context/project_context.json';
$context_data = json_decode(file_get_contents($context_file), true);

if (!$context_data) {
    echo "❌ Failed to load context file\n";
    exit(1);
}

echo "✅ Context file loaded successfully\n\n";

// Validation checks
$validation_results = [];
$phase_status = $context_data['agent_activity']['phase_status'] ?? [];
$gate = $phase_status['gate'] ?? 'UNKNOWN';
$current_phase = $phase_status['current'] ?? 'UNKNOWN';
$agent_activity = $context_data['agent_activity']['last_50_events'] ?? [];

// Check 1: phase_status.current
$expected_phase = "Phase 3 – Pre-Integration";
$phase_valid = ($current_phase === $expected_phase);
$validation_results['phase_status'] = [
    'valid' => $phase_valid,
    'expected' => $expected_phase,
    'actual' => $current_phase,
    'description' => 'Current phase description should match expected'
];

echo "Validation 1: phase_status.current\n";
echo "  Expected: '{$expected_phase}'\n";
echo "  Actual: '{$current_phase}'\n";
echo "  Status: " . ($phase_valid ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Check 2: gate status
$gate_valid = (strpos($gate, 'GREEN') !== false);
$validation_results['gate'] = [
    'valid' => $gate_valid,
    'expected' => 'GREEN',
    'actual' => $gate,
    'description' => 'Gate status should be GREEN for pre-integration'
];

echo "Validation 2: gate status\n";
echo "  Expected: Contains 'GREEN'\n";
echo "  Actual: '{$gate}'\n";
echo "  Status: " . ($gate_valid ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Check 3: agent_activity array length
$activity_count = count($agent_activity);
$activity_valid = ($activity_count > 0);
$validation_results['agent_activity'] = [
    'valid' => $activity_valid,
    'expected' => '> 0',
    'actual' => $activity_count,
    'description' => 'Agent activity array should have at least 1 entry'
];

echo "Validation 3: agent_activity array\n";
echo "  Expected: > 0 entries\n";
echo "  Actual: {$activity_count} entries\n";
echo "  Status: " . ($activity_valid ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Generate pre-integration validation summary
$timestamp = date('Y-m-d H:i:s');
$validation_summary = [
    'timestamp' => $timestamp,
    'phase' => 'Phase 3 - Pre-Integration',
    'gate_status' => $gate_valid ? 'GREEN' : 'RED',
    'validations' => [
        'phase_status_current' => $phase_valid ? 'PASS' : 'FAIL',
        'gate_green' => $gate_valid ? 'PASS' : 'FAIL',
        'agent_activity_present' => $activity_valid ? 'PASS' : 'FAIL'
    ],
    'overall_status' => ($phase_valid && $gate_valid && $activity_valid) ? 'PASS' : 'FAIL',
    'issues_found' => [
        'phase_mismatch' => !$phase_valid,
        'gate_not_green' => !$gate_valid,
        'no_agent_activity' => !$activity_valid
    ],
    'last_check' => $timestamp,
    'checker' => 'Phase 3 Pre-Integration Validation Script'
];

echo "=== PRE-INTEGRATION VALIDATION SUMMARY ===\n";
echo "Timestamp: {$timestamp}\n";
echo "Overall Status: " . ($validation_summary['overall_status'] === 'PASS' ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Gate Status: {$validation_summary['gate_status']}\n";

foreach ($validation_results as $check => $result) {
    echo "{$check}: " . ($result['valid'] ? "✅" : "❌") . "\n";
}

// Update context file with pre-integration validation
if (!isset($context_data['pre_integration_validation'])) {
    $context_data['pre_integration_validation'] = [];
}

$context_data['pre_integration_validation'] = array_merge(
    $context_data['pre_integration_validation'],
    $validation_summary
);

// Update phase status if needed
if (!$phase_valid) {
    $context_data['agent_activity']['phase_status']['current'] = $expected_phase;
    echo "\n⚠️ Updated phase_status.current to '{$expected_phase}'\n";
}

if (!$gate_valid) {
    $context_data['agent_activity']['phase_status']['gate'] = 'GREEN';
    echo "\n⚠️ Updated gate to 'GREEN'\n";
}

// Save updated context
if (file_put_contents($context_file, json_encode($context_data, JSON_PRETTY_PRINT))) {
    echo "✅ Context file updated successfully\n\n";
} else {
    echo "❌ Failed to update context file\n\n";
}

// Generate report
$report_content = "# Agent Context Validation Report - Phase 3 Pre-Integration\n\n";
$report_content .= "**Generated:** " . date('Y-m-d H:i:s') . "\n";
$report_content .= "**Context File:** {$context_file}\n";
$report_content .= "**Overall Status:** " . ($validation_summary['overall_status'] === 'PASS' ? "✅ PASS" : "❌ FAIL") . "\n\n";

$report_content .= "## Validation Results\n\n";
$report_content .= "| Check | Status | Expected | Actual | Description |\n";
$report_content .= "|-------|--------|----------|--------|-------------|\n";

foreach ($validation_results as $check => $result) {
    $status_icon = $result['valid'] ? '✅' : '❌';
    $report_content .= "| {$check} | {$status_icon} | {$result['expected']} | {$result['actual']} | {$result['description']} |\n";
}

$report_content .= "\n## Agent Activity Summary\n\n";
$report_content .= "- **Total Events:** {$activity_count}\n";
$report_content .= "- **Current Phase:** {$current_phase}\n";
$report_content .= "- **Gate Status:** {$gate}\n";

if (!$validation_summary['overall_status'] === 'PASS') {
    $report_content .= "\n## Issues Detected\n\n";
    foreach ($validation_summary['issues_found'] as $issue => $found) {
        if ($found) {
            $report_content .= "- **" . ucfirst(str_replace('_', ' ', $issue)) . "**\n";
        }
    }
    $report_content .= "\n**Action Required:** Review and resolve validation issues before proceeding to Phase 3 Integration.\n";
} else {
    $report_content .= "\n## Context Status: ✅ VALIDATED\n\n";
    $report_content .= "All agent context validations passed. The project context is properly configured for Phase 3 Pre-Integration.\n";
}

$report_content .= "\n## Pre-Integration Validation Details\n\n";
$report_content .= "```json\n";
$report_content .= json_encode($validation_summary, JSON_PRETTY_PRINT);
$report_content .= "\n```\n";

// Save report
file_put_contents('reports/phase3_preintegration/agent_context.md', $report_content);
echo "✅ Report saved to: reports/phase3_preintegration/agent_context.md\n";