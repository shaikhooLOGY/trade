<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

// Test email sending
$result = sendMail('shaikhoology@gmail.com', 'Test Subject', '<h1>Test Email</h1>');
if ($result) {
    echo "Email sent successfully!";
} else {
    echo "Failed to send email!";
}
?>