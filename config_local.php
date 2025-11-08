<?php
/**
 * config_local.php - Local Development Configuration
 * This file provides email configuration specifically for local development
 */

// Local Development SMTP Configuration
// Option 1: Gmail SMTP (requires app password)
// Option 2: Mailtrap (free, recommended for testing)
// Option 3: Development only - Show OTP on screen

// ===========================================
// OPTION 1: GMAIL SMTP (Production-like)
// ===========================================
// To use Gmail SMTP, you need:
// 1. An app password (not your regular Gmail password)
// 2. Enable 2-factor authentication on your Gmail account
// 3. Generate an app password at: https://myaccount.google.com/apppasswords

// Uncomment and fill these with your Gmail credentials:
// define('SMTP_HOST', 'smtp.gmail.com');
// define('SMTP_PORT', 587);
// define('SMTP_SECURE', 'tls');
// define('SMTP_USER', 'your-email@gmail.com');
// define('SMTP_PASS', 'your-app-password');
// define('MAIL_FROM', 'your-email@gmail.com');
// define('MAIL_FROM_NAME', 'Local Development - Shaikhoology');

// ===========================================
// OPTION 2: MAILTRAP (Free Email Testing Service)
// ===========================================
// 1. Sign up at https://mailtrap.io (free account)
// 2. Get your SMTP credentials from Mailtrap dashboard
// 3. Use these credentials below:

// Uncomment and fill these with your Mailtrap credentials:
// define('SMTP_HOST', 'sandbox.smtp.mailtrap.io');
// define('SMTP_PORT', 2525);
// define('SMTP_SECURE', '');
// define('SMTP_USER', 'your-mailtrap-username');
// define('SMTP_PASS', 'your-mailtrap-password');
// define('MAIL_FROM', 'noreply@shaikhoology.com');
// define('MAIL_FROM_NAME', 'Shaikhoology - Local Dev');

// ===========================================
// OPTION 3: LOCAL DEVELOPMENT SIMULATION
// ===========================================
// For testing without real email sending
// This will log all emails to a file and show OTP on screen

// Production mode - enable real email sending via SMTP
// Development mode - disable real email sending
define('DEV_MODE_EMAIL', false);
define('DEV_EMAIL_LOG', __DIR__ . '/logs/dev_emails.log');
define('DEV_SHOW_OTP', false); // Set to false to hide OTP on screen

// ===========================================
// OPTION 4: LOCALHOST SMTP (if you have one set up)
// ===========================================
// If you have a local SMTP server like:
// - MailHog (https://github.com/mailhog/MailHog)
// - MailDev (https://maildev.github.io/maildev/)
// - Local Postfix/QMail configuration

// Uncomment and modify these if you have a local SMTP server:
// define('SMTP_HOST', 'localhost');
// define('SMTP_PORT', 1025); // MailHog default
// define('SMTP_SECURE', '');
// define('SMTP_USER', '');
// define('SMTP_PASS', '');
// define('MAIL_FROM', 'noreply@shaikhoology.local');
// define('MAIL_FROM_NAME', 'Shaikhoology Local');

// ===========================================
// RECOMMENDED SETUP FOR THIS PROJECT
// ===========================================
// For immediate local development, use the DEVELOPMENT SIMULATION
// The DEV_MODE_EMAIL configuration above is already enabled.