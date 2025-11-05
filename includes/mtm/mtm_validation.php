<?php
/**
 * MTM Validation Module
 * 
 * Provides input validation functions for TMS-MTM module
 */

if (!function_exists('validate_enroll_input')) {
    /**
     * Validate enrollment input data
     * 
     * @param array $input Input data from request
     * @return array ['valid' => bool, 'errors' => array]
     */
    function validate_enroll_input(array $input): array {
        $errors = [];
        
        // Validate model_id
        if (!isset($input['model_id']) || !is_numeric($input['model_id'])) {
            $errors['model_id'] = 'Valid model ID is required';
        } elseif ((int)$input['model_id'] <= 0) {
            $errors['model_id'] = 'Model ID must be a positive integer';
        }
        
        // Validate tier
        if (!isset($input['tier']) || !is_valid_enum($input['tier'], ['basic', 'intermediate', 'advanced'])) {
            $errors['tier'] = 'Tier must be one of: basic, intermediate, advanced';
        }
        
        // Validate CSRF token
        if (!isset($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
            $errors['csrf_token'] = 'Invalid CSRF token';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

if (!function_exists('validate_trade_input')) {
    /**
     * Validate trade creation input data
     * 
     * @param array $input Input data from request
     * @return array ['valid' => bool, 'errors' => array]
     */
    function validate_trade_input(array $input): array {
        $errors = [];
        
        // Validate symbol
        if (!isset($input['symbol']) || trim($input['symbol']) === '') {
            $errors['symbol'] = 'Symbol is required';
        } elseif (strlen(trim($input['symbol'])) > 32) {
            $errors['symbol'] = 'Symbol must be 32 characters or less';
        } else {
            $input['symbol'] = sanitize_string($input['symbol']);
        }
        
        // Validate side
        if (!isset($input['side']) || !in_array($input['side'], ['buy', 'sell'], true)) {
            $errors['side'] = 'Side must be either "buy" or "sell"';
        }
        
        // Validate quantity
        if (!isset($input['quantity']) || !is_numeric($input['quantity'])) {
            $errors['quantity'] = 'Valid quantity is required';
        } elseif ((float)$input['quantity'] <= 0) {
            $errors['quantity'] = 'Quantity must be greater than zero';
        }
        
        // Validate price
        if (!isset($input['price']) || !is_numeric($input['price'])) {
            $errors['price'] = 'Valid price is required';
        } elseif ((float)$input['price'] <= 0) {
            $errors['price'] = 'Price must be greater than zero';
        }
        
        // Validate opened_at
        if (!isset($input['opened_at']) || trim($input['opened_at']) === '') {
            $errors['opened_at'] = 'Opened date is required';
        } elseif (!strtotime($input['opened_at'])) {
            $errors['opened_at'] = 'Invalid date format';
        }
        
        // Validate notes (optional)
        if (isset($input['notes']) && strlen($input['notes']) > 1000) {
            $errors['notes'] = 'Notes must be 1000 characters or less';
        } elseif (isset($input['notes'])) {
            $input['notes'] = sanitize_string($input['notes']);
        }
        
        // Validate CSRF token for POST requests
        if (!isset($input['csrf_token']) || !csrf_verify($input['csrf_token'])) {
            $errors['csrf_token'] = 'Invalid CSRF token';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'sanitized' => $input
        ];
    }
}

if (!function_exists('sanitize_string')) {
    /**
     * Sanitize string input
     * 
     * @param string $str String to sanitize
     * @return string Sanitized string
     */
    function sanitize_string(string $str): string {
        return trim(strip_tags($str));
    }
}

if (!function_exists('is_valid_enum')) {
    /**
     * Check if value is valid enum
     * 
     * @param mixed $val Value to check
     * @param array $allowed Allowed values
     * @return bool True if valid
     */
    function is_valid_enum($val, array $allowed): bool {
        return in_array($val, $allowed, true);
    }
}

if (!function_exists('validate_pagination_params')) {
    /**
     * Validate pagination parameters
     * 
     * @param array $input Input data
     * @return array ['valid' => bool, 'offset' => int, 'limit' => int]
     */
    function validate_pagination_params(array $input): array {
        $offset = isset($input['offset']) && is_numeric($input['offset']) ? max(0, (int)$input['offset']) : 0;
        $limit = isset($input['limit']) && is_numeric($input['limit']) ? min(200, max(1, (int)$input['limit'])) : 50;
        
        return [
            'valid' => true,
            'offset' => $offset,
            'limit' => $limit
        ];
    }
}