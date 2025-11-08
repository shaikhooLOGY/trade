<?php
// Privacy Policy - GDPR/SEBI Compliant
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$hideNav = true;
include __DIR__ . '/header.php';
?>
<div style="max-width:900px;margin:20px auto;padding:30px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(16,24,40,.06)">
  <h1 style="margin:0 0 20px;color:#222">🔒 Privacy Policy</h1>
  
  <div style="font-size:14px;line-height:1.6;color:#444">
    
    <p style="background:#f8f9fa;padding:15px;border-radius:8px;margin-bottom:20px">
      <strong>Your privacy is important to us.</strong> This policy explains how we collect, use, and protect your personal information when you use Shaikhoology's trading education platform.
    </p>
    
    <h2 style="color:#333;margin-top:25px">1. Information We Collect</h2>
    
    <h3 style="color:#555;margin-top:20px">Personal Information</h3>
    <ul>
      <li><strong>Account Data:</strong> Name, email address, username, password (encrypted)</li>
      <li><strong>Profile Information:</strong> Trading experience, investment goals, risk tolerance</li>
      <li><strong>Verification Data:</strong> Phone number, address (if required for compliance)</li>
      <li><strong>Communication Data:</strong> Support tickets, feedback, survey responses</li>
    </ul>
    
    <h3 style="color:#555;margin-top:20px">Usage Information</h3>
    <ul>
      <li><strong>Platform Activity:</strong> Pages visited, features used, time spent</li>
      <li><strong>Device Information:</strong> IP address, browser type, operating system</li>
      <li><strong>Performance Data:</strong> App crashes, loading times, error logs</li>
      <li><strong>Analytics:</strong> User behavior patterns for platform improvement</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">2. How We Use Your Information</h2>
    
    <h3 style="color:#555;margin-top:20px">Primary Uses</h3>
    <ul>
      <li><strong>Account Management:</strong> Create and maintain your trading education account</li>
      <li><strong>Service Delivery:</strong> Provide personalized learning content and insights</li>
      <li><strong>Communication:</strong> Send important account notifications and educational updates</li>
      <li><strong>Security:</strong> Protect against fraud, abuse, and security threats</li>
      <li><strong>Compliance:</strong> Meet legal and regulatory requirements (SEBI, KYC, AML)</li>
    </ul>
    
    <h3 style="color:#555;margin-top:20px">Secondary Uses</h3>
    <ul>
      <li><strong>Platform Improvement:</strong> Analyze usage patterns to enhance user experience</li>
      <li><strong>Marketing:</strong> Send educational content and trading insights (with consent)</li>
      <li><strong>Research:</strong> Conduct anonymized studies to improve financial education</li>
      <li><strong>Third-party Integration:</strong> Connect with trading platforms and services (with consent)</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">3. Information Sharing</h2>
    
    <h3 style="color:#555;margin-top:20px">We Do NOT Sell Your Data</h3>
    <p>We never sell, rent, or trade your personal information to third parties for marketing purposes.</p>
    
    <h3 style="color:#555;margin-top:20px">Limited Sharing Scenarios</h3>
    <ul>
      <li><strong>Service Providers:</strong> Cloud hosting, email services, payment processors (bound by confidentiality)</li>
      <li><strong>Legal Requirements:</strong> When required by law, court order, or regulatory request</li>
      <li><strong>Business Transfer:</strong> In case of merger, acquisition, or asset sale (with notice)</li>
      <li><strong>Safety & Security:</strong> To protect rights, property, or safety of users and others</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">4. Data Protection & Security</h2>
    
    <h3 style="color:#555;margin-top:20px">Security Measures</h3>
    <ul>
      <li><strong>Encryption:</strong> All data encrypted in transit (HTTPS) and at rest</li>
      <li><strong>Access Controls:</strong> Role-based access, multi-factor authentication</li>
      <li><strong>Regular Audits:</strong> Security assessments and vulnerability testing</li>
      <li><strong>Compliance:</strong> Adherence to industry security standards (ISO 27001)</li>
    </ul>
    
    <h3 style="color:#555;margin-top:20px">Data Retention</h3>
    <ul>
      <li><strong>Account Data:</strong> Retained while account is active plus 7 years (regulatory requirement)</li>
      <li><strong>Usage Data:</strong> Anonymized after 2 years for analytics purposes</li>
      <li><strong>Communication Data:</strong> Retained for 3 years for customer service</li>
      <li><strong>Security Logs:</strong> Retained for 1 year for security purposes</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">5. Your Rights & Choices</h2>
    
    <h3 style="color:#555;margin-top:20px">Data Subject Rights (GDPR)</h3>
    <ul>
      <li><strong>Access:</strong> Request a copy of your personal data</li>
      <li><strong>Rectification:</strong> Correct inaccurate or incomplete information</li>
      <li><strong>Erasure:</strong> Request deletion of your personal data ("right to be forgotten")</li>
      <li><strong>Portability:</strong> Receive your data in a machine-readable format</li>
      <li><strong>Restriction:</strong> Limit how we process your data</li>
      <li><strong>Objection:</strong> Object to processing for direct marketing</li>
    </ul>
    
    <h3 style="color:#555;margin-top:20px">Communication Preferences</h3>
    <ul>
      <li><strong>Email Marketing:</strong> Unsubscribe using links in emails or account settings</li>
      <li><strong>Push Notifications:</strong> Control in browser/ app settings</li>
      <li><strong>SMS:</strong> Opt-out using "STOP" in text messages</li>
      <li><strong>Account Deletion:</strong> Request account closure through settings or support</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">6. International Transfers</h2>
    <p>Your data may be processed in countries other than your own. We ensure appropriate safeguards are in place through:</p>
    <ul>
      <li><strong>Adequacy Decisions:</strong> European Commission approved countries</li>
      <li><strong>Standard Contractual Clauses:</strong> EU-approved data transfer agreements</li>
      <li><strong>Certification:</strong> Privacy Shield or similar frameworks where applicable</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">7. Children's Privacy</h2>
    <p>Our service is not intended for children under 18. We do not knowingly collect personal information from minors. If we become aware that we have collected such information, we will take steps to delete it promptly.</p>
    
    <h2 style="color:#333;margin-top:25px">8. Third-Party Services</h2>
    <p>Our platform may contain links to third-party websites or integrate with external services. This privacy policy does not apply to those services. We encourage you to review their privacy policies.</p>
    
    <h2 style="color:#333;margin-top:25px">9. Changes to This Policy</h2>
    <p>We may update this privacy policy from time to time. We will notify you of significant changes via:</p>
    <ul>
      <li>Email notification to your registered address</li>
      <li>In-app notification upon next login</li>
      <li>Prominent notice on our website</li>
    </ul>
    
    <h2 style="color:#333;margin-top:25px">10. Contact Information</h2>
    <p>For privacy-related questions or to exercise your rights, contact us:</p>
    <ul>
      <li><strong>Email:</strong> privacy@shaikhoology.com</li>
      <li><strong>Data Protection Officer:</strong> dpo@shaikhoology.com</li>
      <li><strong>Address:</strong> Shaikhoology Privacy Office, [Your Business Address], Mumbai, India</li>
      <li><strong>Response Time:</strong> We respond to privacy requests within 30 days</li>
    </ul>
    
    <p style="margin-top:30px;color:#666;font-size:12px">
      <strong>Last Updated:</strong> <?= date('F d, Y') ?><br>
      <strong>Effective Date:</strong> <?= date('F d, Y') ?><br>
      <strong>Version:</strong> 2.0
    </p>
    
  </div>
</div>
<?php include __DIR__ . '/footer.php'; ?>