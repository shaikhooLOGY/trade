<?php
// system/mtm_verifier.php
// MTM Rule Engine and Trade Verification System
// Used by trade creation, editing, and closure processes

if (!function_exists('mtm_resolve_rules')) {
    /**
     * Resolve complete rules for a task (form fields + JSON overrides)
     * @param array $task Task row from mtm_tasks
     * @return array Resolved rules array
     */
    function mtm_resolve_rules(array $task): array {
        // Start with form field defaults
        $rules = [
            'min_trades' => (int)($task['min_trades'] ?? 0),
            'time_window_days' => (int)($task['time_window_days'] ?? 0),
            'require_sl' => (bool)($task['require_sl'] ?? false),
            'max_risk_pct' => $task['max_risk_pct'] ? (float)$task['max_risk_pct'] : null,
            'max_position_pct' => $task['max_position_pct'] ? (float)$task['max_position_pct'] : null,
            'min_rr' => $task['min_rr'] ? (float)$task['min_rr'] : null,
            'require_analysis_link' => (bool)($task['require_analysis_link'] ?? false),
            'weekly_min_trades' => (int)($task['weekly_min_trades'] ?? 0),
            'weeks_consistency' => (int)($task['weeks_consistency'] ?? 0),
        ];

        // Apply JSON overrides if present
        if (!empty($task['rule_json'])) {
            $jsonRules = json_decode($task['rule_json'], true);
            if (is_array($jsonRules)) {
                $rules = array_merge($rules, $jsonRules);
            }
        }

        return $rules;
    }
}

if (!function_exists('mtm_evaluate_trade_compliance')) {
    /**
     * Evaluate a trade against resolved rules
     * @param array $trade Trade data array
     * @param array $rules Resolved rules from mtm_resolve_rules()
     * @return array ['compliant' => bool, 'violations' => array, 'warnings' => array]
     */
    function mtm_evaluate_trade_compliance(array $trade, array $rules): array {
        $violations = [];
        $warnings = [];

        // Calculate derived values
        $entry_price = (float)($trade['entry_price'] ?? 0);
        $stop_loss = isset($trade['stop_loss']) && $trade['stop_loss'] !== '' ? (float)$trade['stop_loss'] : null;
        $target_price = isset($trade['target_price']) && $trade['target_price'] !== '' ? (float)$trade['target_price'] : null;
        $position_percent = isset($trade['position_percent']) && $trade['position_percent'] !== '' ? (float)$trade['position_percent'] : null;
        $outcome = strtoupper(trim($trade['outcome'] ?? ''));
        $analysis_link = !empty($trade['analysis_link']);
        $notes = trim($trade['notes'] ?? '');
        $entry_date = $trade['entry_date'] ?? null;

        // 1. Stop Loss requirement
        if ($rules['require_sl'] && $stop_loss === null) {
            $violations[] = 'Stop loss is required';
        }

        // 2. Risk percentage check
        if ($rules['max_risk_pct'] !== null && $stop_loss !== null && $entry_price > 0) {
            $risk_pct = abs(($entry_price - $stop_loss) / $entry_price) * 100;
            if ($risk_pct > $rules['max_risk_pct']) {
                $violations[] = "Risk percentage ({$risk_pct}%) exceeds maximum allowed ({$rules['max_risk_pct']}%)";
            }
        }

        // 3. Position size check
        if ($rules['max_position_pct'] !== null && $position_percent !== null) {
            if ($position_percent > $rules['max_position_pct']) {
                $violations[] = "Position size ({$position_percent}%) exceeds maximum allowed ({$rules['max_position_pct']}%)";
            }
        }

        // 4. Risk-Reward ratio check
        if ($rules['min_rr'] !== null && $stop_loss !== null && $target_price !== null && $entry_price > 0) {
            $risk = abs($entry_price - $stop_loss);
            $reward = abs($target_price - $entry_price);
            if ($risk > 0) {
                $rr = $reward / $risk;
                if ($rr < $rules['min_rr']) {
                    $violations[] = "Risk-Reward ratio ({$rr}) below minimum required ({$rules['min_rr']})";
                }
            }
        }

        // 5. Analysis link requirement
        if ($rules['require_analysis_link'] && !$analysis_link) {
            $violations[] = 'Analysis link is required';
        }

        // 6. Allowed outcomes check
        if (isset($rules['allowed_outcomes']) && is_array($rules['allowed_outcomes']) && !empty($outcome)) {
            if (!in_array($outcome, array_map('strtoupper', $rules['allowed_outcomes']))) {
                $violations[] = "Outcome '{$outcome}' not in allowed outcomes: " . implode(', ', $rules['allowed_outcomes']);
            }
        }

        // 7. Chart tag requirement
        if (isset($rules['require_chart_tag']) && !empty($rules['require_chart_tag'])) {
            $tag = strtolower(trim($rules['require_chart_tag']));
            if (stripos($notes, $tag) === false) {
                $violations[] = "Chart tag '{$rules['require_chart_tag']}' required in notes";
            }
        }

        // 8. Market restriction
        if (isset($rules['market']) && !empty($rules['market'])) {
            $marketcap = strtoupper(trim($trade['marketcap'] ?? ''));
            if ($marketcap !== strtoupper($rules['market'])) {
                $violations[] = "Market must be '{$rules['market']}' (current: {$marketcap})";
            }
        }

        // 9. Minimum capital check
        if (isset($rules['min_capital']) && $rules['min_capital'] > 0) {
            // Would need user capital context - add warning for now
            $warnings[] = "Minimum capital requirement: ₹" . number_format($rules['min_capital']);
        }

        // 10. Average down prohibition
        if (isset($rules['forbid_avg_down']) && $rules['forbid_avg_down']) {
            // Check if this is an averaging down trade (logic would need position context)
            $warnings[] = "Averaging down is not allowed for this task";
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings
        ];
    }
}

if (!function_exists('mtm_get_enforcement_tier')) {
    /**
     * Get enforcement behavior based on model difficulty
     * @param string $difficulty Model difficulty (easy/moderate/hard)
     * @return string 'nudge'|'soft_block'|'hard_block'
     */
    function mtm_get_enforcement_tier(string $difficulty): string {
        $difficulty = strtolower($difficulty);
        switch ($difficulty) {
            case 'easy':
            case 'basic':
                return 'nudge';
            case 'moderate':
            case 'intermediate':
                return 'soft_block';
            case 'hard':
            case 'advanced':
            default:
                return 'hard_block';
        }
    }
}

if (!function_exists('mtm_evaluate_task_progress')) {
    /**
     * Evaluate if a task should be marked as passed based on completed trades
     * @param mysqli $db Database connection
     * @param int $enrollment_id
     * @param int $task_id
     * @return array ['should_pass' => bool, 'reason' => string, 'trades_count' => int]
     */
    function mtm_evaluate_task_progress(mysqli $db, int $enrollment_id, int $task_id): array {
        // Get task details
        $task = null;
        $stmt = $db->prepare("SELECT * FROM mtm_tasks WHERE id = ?");
        $stmt->bind_param('i', $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $task = $row;
        }
        $stmt->close();

        if (!$task) {
            return ['should_pass' => false, 'reason' => 'Task not found', 'trades_count' => 0];
        }

        $rules = mtm_resolve_rules($task);
        $min_trades = $rules['min_trades'];

        if ($min_trades <= 0) {
            return ['should_pass' => true, 'reason' => 'No minimum trade requirement', 'trades_count' => 0];
        }

        // Count compliant closed trades for this task
        $stmt = $db->prepare("
            SELECT COUNT(*) as trade_count
            FROM trades
            WHERE enrollment_id = ?
            AND task_id = ?
            AND compliance_status IN ('pass', 'override')
            AND outcome NOT IN ('OPEN', '')
            AND deleted_at IS NULL
        ");
        $stmt->bind_param('ii', $enrollment_id, $task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $trade_count = 0;
        if ($result && $row = $result->fetch_assoc()) {
            $trade_count = (int)$row['trade_count'];
        }
        $stmt->close();

        $should_pass = $trade_count >= $min_trades;

        return [
            'should_pass' => $should_pass,
            'reason' => $should_pass ? "Met minimum trades ({$trade_count}/{$min_trades})" : "Insufficient trades ({$trade_count}/{$min_trades})",
            'trades_count' => $trade_count
        ];
    }
}

if (!function_exists('mtm_update_task_progress')) {
    /**
     * Update task progress after trade completion
     * @param mysqli $db Database connection
     * @param int $enrollment_id
     * @param int $task_id
     * @return bool Success
     */
    function mtm_update_task_progress(mysqli $db, int $enrollment_id, int $task_id): bool {
        $evaluation = mtm_evaluate_task_progress($db, $enrollment_id, $task_id);

        if ($evaluation['should_pass']) {
            // Mark task as passed
            $stmt = $db->prepare("
                UPDATE mtm_task_progress
                SET status = 'passed', passed_at = NOW(), last_evaluated_at = NOW()
                WHERE enrollment_id = ? AND task_id = ?
            ");
            $stmt->bind_param('ii', $enrollment_id, $task_id);
            $success = $stmt->execute();
            $stmt->close();

            if ($success) {
                // Unlock next task in sequence
                mtm_unlock_next_task($db, $enrollment_id, $task_id);
            }

            return $success;
        } else {
            // Update evaluation timestamp
            $stmt = $db->prepare("
                UPDATE mtm_task_progress
                SET last_evaluated_at = NOW()
                WHERE enrollment_id = ? AND task_id = ?
            ");
            $stmt->bind_param('ii', $enrollment_id, $task_id);
            $success = $stmt->execute();
            $stmt->close();

            return $success;
        }
    }
}

if (!function_exists('mtm_unlock_next_task')) {
    /**
     * Unlock the next task in sequence after current task passes
     * @param mysqli $db Database connection
     * @param int $enrollment_id
     * @param int $current_task_id
     */
    function mtm_unlock_next_task(mysqli $db, int $enrollment_id, int $current_task_id): void {
        // Get current task's model and sort order
        $stmt = $db->prepare("
            SELECT mt.model_id, mt.sort_order
            FROM mtm_tasks mt
            JOIN mtm_task_progress mtp ON mt.id = mtp.task_id
            WHERE mtp.enrollment_id = ? AND mtp.task_id = ?
        ");
        $stmt->bind_param('ii', $enrollment_id, $current_task_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $current = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$current) return;

        $model_id = (int)$current['model_id'];
        $current_sort = (int)$current['sort_order'];

        // Find next task in sequence
        $stmt = $db->prepare("
            SELECT mt.id
            FROM mtm_tasks mt
            LEFT JOIN mtm_task_progress mtp ON mt.id = mtp.task_id AND mtp.enrollment_id = ?
            WHERE mt.model_id = ? AND mt.sort_order > ?
            AND (mtp.status IS NULL OR mtp.status = 'locked')
            ORDER BY mt.sort_order ASC
            LIMIT 1
        ");
        $stmt->bind_param('iii', $enrollment_id, $model_id, $current_sort);
        $stmt->execute();
        $result = $stmt->get_result();
        $next_task = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if ($next_task) {
            // Unlock next task
            $stmt = $db->prepare("
                INSERT INTO mtm_task_progress (enrollment_id, task_id, status, unlocked_at)
                VALUES (?, ?, 'unlocked', NOW())
                ON DUPLICATE KEY UPDATE status = 'unlocked', unlocked_at = NOW()
            ");
            $stmt->bind_param('ii', $enrollment_id, $next_task['id']);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('mtm_get_active_enrollment')) {
    /**
     * Get user's active (approved) MTM enrollment
     * @param mysqli $db Database connection
     * @param int $user_id
     * @return array|null Enrollment data or null
     */
    function mtm_get_active_enrollment(mysqli $db, int $user_id): ?array {
        $stmt = $db->prepare("
            SELECT e.*, m.title, m.difficulty, m.status as model_status
            FROM mtm_enrollments e
            JOIN mtm_models m ON e.model_id = m.id
            WHERE e.user_id = ? AND e.status = 'approved'
            ORDER BY e.approved_at DESC
            LIMIT 1
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollment = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $enrollment;
    }
}

if (!function_exists('mtm_get_active_enrollments')) {
    /**
     * Get all active MTM enrollments for a user (future-proof for multi-enrollment)
     * @param mysqli $db
     * @param int $user_id
     * @return array
     */
    function mtm_get_active_enrollments(mysqli $db, int $user_id): array {
        $stmt = $db->prepare("
            SELECT e.*, m.title, m.difficulty, m.status AS model_status
            FROM mtm_enrollments e
            JOIN mtm_models m ON e.model_id = m.id
            WHERE e.user_id = ? AND e.status = 'approved'
            ORDER BY e.approved_at DESC
        ");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('mtm_get_current_task')) {
    /**
     * Get the currently unlocked task for an enrollment
     * @param mysqli $db Database connection
     * @param int $enrollment_id
     * @return array|null Task data or null
     */
    function mtm_get_current_task(mysqli $db, int $enrollment_id): ?array {
        $stmt = $db->prepare("
            SELECT mt.*, mtp.status, mtp.attempts
            FROM mtm_task_progress mtp
            JOIN mtm_tasks mt ON mtp.task_id = mt.id
            WHERE mtp.enrollment_id = ? AND mtp.status IN ('unlocked', 'in_progress')
            ORDER BY mt.sort_order ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $enrollment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $task = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        return $task;
    }
}

if (!function_exists('mtm_initialize_task_progress')) {
    /**
     * Initialize task progress for a new enrollment (unlock first task)
     * @param mysqli $db Database connection
     * @param int $enrollment_id
     * @return bool Success
     */
    function mtm_initialize_task_progress(mysqli $db, int $enrollment_id): bool {
        // Get enrollment model
        $stmt = $db->prepare("SELECT model_id FROM mtm_enrollments WHERE id = ?");
        $stmt->bind_param('i', $enrollment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $enrollment = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$enrollment) return false;

        // Get first task
        $stmt = $db->prepare("
            SELECT id FROM mtm_tasks
            WHERE model_id = ?
            ORDER BY sort_order ASC
            LIMIT 1
        ");
        $stmt->bind_param('i', $enrollment['model_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $first_task = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$first_task) return false;

        // Unlock first task
        $stmt = $db->prepare("
            INSERT INTO mtm_task_progress (enrollment_id, task_id, status, unlocked_at)
            VALUES (?, ?, 'unlocked', NOW())
        ");
        $stmt->bind_param('ii', $enrollment_id, $first_task['id']);
        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }
}

// End of MTM Verifier
