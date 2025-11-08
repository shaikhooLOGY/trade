<?php
// Legal Disclaimer Page - SEBI Compliant
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$hideNav = true;
include __DIR__ . '/header.php';
?>
<div style="max-width:900px;margin:20px auto;padding:30px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(16,24,40,.06)">
  <h1 style="margin:0 0 20px;color:#222">📋 Legal Disclaimer & Terms</h1>
  
  <div style="font-size:14px;line-height:1.6;color:#444">
    
    <h2 style="color:#333;margin-top:25px">Trading Risk Disclaimer</h2>
    <p><strong>Trading in securities involves substantial risk of loss and is not suitable for all investors.</strong> Past performance is not indicative of future results. All trading decisions are your sole responsibility. Shaikhoology and its affiliates are not liable for any financial losses resulting from trading activities.</p>
    
    <h2 style="color:#333;margin-top:25px">Regulatory Compliance</h2>
    <p>This platform operates under the regulations of the Securities and Exchange Board of India (SEBI). All users must comply with applicable securities laws, including but not limited to:</p>
    <ul>
      <li>Providing accurate and truthful information during registration</li>
      <li>Complying with Know Your Customer (KYC) requirements when applicable</li>
      <li>Adhering to anti-money laundering (AML) regulations</li>
      <li>Maintaining the confidentiality of account credentials</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Investment Advice Disclaimer</h2>
    <p>All information provided on this platform is for <strong>educational and informational purposes only</strong> and should not be construed as:</p>
    <ul>
      <li>Investment advice or recommendations</li>
      <li>Solicitation to buy or sell securities</li>
      <li>Professional financial advice</li>
      <li>Guarantee of future trading performance</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Data Protection & Privacy</h2>
    <p>Your personal and financial data is protected under:</p>
    <ul>
      <li>Information Technology Act, 2000</li>
      <li>General Data Protection Regulation (GDPR) - for EU users</li>
      <li>Personal Data Protection Bill, 2023 (when enacted)</li>
      <li>SEBI's Data Protection guidelines</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Account Security</h2>
    <p>Users are solely responsible for:</p>
    <ul>
      <li>Maintaining the confidentiality of their login credentials</li>
      <li>All activities that occur under their account</li>
      <li>Immediately reporting any unauthorized access</li>
      <li>Using strong passwords and enabling two-factor authentication when available</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Email & Communication Consent</h2>
    <p>By registering, you consent to:</p>
    <ul>
      <li>Receive transactional emails (account verification, password resets)</li>
      <li>Receive educational content and trading insights</li>
      <li>Platform notifications and service announcements</li>
      <li>Email access to Gmail/email addresses for account linking purposes (optional)</li>
      <li>Marketing communications (unsubscribe available at any time)</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Limitation of Liability</h2>
    <p>Shaikhoology shall not be liable for:</p>
    <ul>
      <li>Direct, indirect, incidental, or consequential damages</li>
      <li>Loss of profits, data, or business opportunities</li>
      <li>Service interruptions or technical failures</li>
      <li>Actions taken by third-party service providers</li>
      <li>Market volatility or trading losses</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">Governing Law</h2>
    <p>These terms are governed by the laws of India. Any disputes shall be subject to the exclusive jurisdiction of courts in Mumbai, Maharashtra.</p>
    
    <h2 style="color:#333;margin-top:25px">Updates & Modifications</h2>
    <p>These terms may be updated from time to time. Continued use of the platform constitutes acceptance of updated terms. Users will be notified of significant changes via email.</p>
    
    <p style="margin-top:30px;color:#666;font-size:12px">
      <strong>Last Updated:</strong> <?= date('F d, Y') ?><br>
      <strong>Effective Date:</strong> <?= date('F d, Y') ?>
    </p>
    
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>