#!/bin/bash
# maintenance/env-example.sh
#
# Environment Configuration Template
# Purpose: Provide environment variable examples for MTM Trading Platform
# Usage: Copy to env.sh and customize values for your environment
#
# Instructions:
# 1. Copy this file: cp env-example.sh env.sh
# 2. Edit env.sh with your specific configuration
# 3. Source the file: source env.sh

# =====================================================
# APPLICATION ENVIRONMENT
# =====================================================

# Base URL for the application
export BASE_URL="http://127.0.0.1:8082"

# Application environment (local, development, staging, production)
export APP_ENV="local"

# Feature flags
export APP_FEATURE_APIS="on"

# =====================================================
# DATABASE CONFIGURATION
# =====================================================

# Database connection parameters (if needed for direct connections)
# export DB_HOST="localhost"
# export DB_PORT="3306"
# export DB_NAME="your_database_name"
# export DB_USER="your_database_user"
# export DB_PASS="your_database_password"

# =====================================================
# SECURITY SETTINGS
# =====================================================

# Session configuration
# export SESSION_LIFETIME="7200"
# export SESSION_NAME="MTM_SESSION"

# CSRF protection
# export CSRF_ENABLED="true"

# Rate limiting
# export RATE_LIMIT_ENABLED="true"

# =====================================================
# EXTERNAL SERVICES
# =====================================================

# Email configuration (if using SMTP)
# export SMTP_HOST="smtp.gmail.com"
# export SMTP_PORT="587"
# export SMTP_USER="your_email@gmail.com"
# export SMTP_PASS="your_app_password"

# Log levels (debug, info, warning, error)
# export LOG_LEVEL="info"

# =====================================================
# DEVELOPMENT SETTINGS
# =====================================================

# Enable debug mode in development
# export DEBUG_MODE="true"

# Show detailed error messages
# export SHOW_ERRORS="true"

# Enable/disable maintenance mode
# export MAINTENANCE_MODE="false"

# =====================================================
# SMOKE TEST CONFIGURATION
# =====================================================

# Default timeout for smoke tests (seconds)
# export SMOKE_TEST_TIMEOUT="30"

# Number of retry attempts for failed tests
# export SMOKE_TEST_RETRIES="3"

# =====================================================
# VERIFICATION
# =====================================================

# Function to display current environment settings
show_env() {
    echo "Current Environment Configuration:"
    echo "================================="
    echo "BASE_URL: ${BASE_URL:-not set}"
    echo "APP_ENV: ${APP_ENV:-not set}"
    echo "APP_FEATURE_APIS: ${APP_FEATURE_APIS:-not set}"
    echo "================================="
}

# Uncomment the line below to automatically show environment on script load
# show_env