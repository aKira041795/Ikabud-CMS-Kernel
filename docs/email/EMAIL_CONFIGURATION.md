# Email Configuration & Usage Guide

## Overview

This application includes a centralized SMTP email system using the kernel's `EmailService`. All modules can send emails consistently through the configured SMTP server (Gmail in this setup).

## Configuration

Email settings are configured via environment variables in `.env`:

```env
# Email Protocol
EMAIL_PROTOCOL=smtp

# SMTP Server Details
EMAIL_SMTP_HOST=smtp.gmail.com
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USER=your-email@gmail.com
EMAIL_SMTP_PASS=your-gmail-app-password

# Encryption
EMAIL_SMTP_CRYPTO=tls

# From Address
EMAIL_FROM_EMAIL=noreply@yourdomain.com
EMAIL_FROM_NAME="Ikabud Kernel"

# Email Format
EMAIL_MAIL_TYPE=html
EMAIL_CHARSET=utf-8
```

**Note:** Gmail App Passwords are required. Use an [App Password](https://myaccount.google.com/apppasswords) instead of your account password for security.

## Usage in Modules

### Basic Email Sending

All modules have access to the `sendEmail()` helper function:

```php
$to = 'user@example.com';
$subject = 'Welcome to Our Service';
$body = 'Hello! Welcome aboard.';

sendEmail($to, $subject, $body);
```

### Sending HTML Emails

Use `buildEmailTemplate()` to wrap your content in a professional branded template:

```php
$content = <<<HTML
<p style="margin: 0 0 20px; color: #4b5563;">
    Thank you for contacting us. We'll get back to you soon.
</p>
HTML;

$body = buildEmailTemplate('Thank You', $content);
sendEmail($to, $subject, $body);
```

### Sending Emails with Call-to-Action Button

```php
$content = '<p>Click the button below to reset your password.</p>';
$body = buildEmailTemplate(
    'Reset Your Password',
    $content,
    'Reset Password',  // Button text
    'https://example.com/reset?token=abc123'  // Button URL
);

sendEmail($to, $subject, $body);
```

## Use Cases

### Contact Form (Already Implemented)

The contact form module sends HTML emails to the configured recipient with submission details:

```
✅ Uses: sendEmail() + buildEmailTemplate()
✅ HTML formatted with sender information
✅ Mobile responsive template
```

### Password Reset (Example Implementation)

```php
function sendPasswordResetEmail(string $email, string $resetLink): bool
{
    $content = <<<HTML
<p style="margin: 0 0 20px; color: #4b5563; font-size: 16px;">
    Someone requested a password reset for your account.
</p>

<p style="margin: 0 0 20px; color: #4b5563; font-size: 16px;">
    If you did not request this, you can ignore this email.
    This link expires in 1 hour.
</p>
HTML;
    
    $body = buildEmailTemplate(
        'Reset Your Password',
        $content,
        'Reset Password',
        $resetLink
    );
    
    return sendEmail($email, 'Password Reset Request', $body);
}
```

### Two-Factor Authentication Code

```php
function send2FACode(string $email, string $code): bool
{
    $content = <<<HTML
<p style="margin: 0 0 20px; color: #4b5563; font-size: 16px;">
    Your login verification code is:
</p>

<div style="text-align: center; padding: 20px; background: #f9fafb; border-radius: 6px; margin: 20px 0;">
    <span style="font-size: 32px; font-weight: 700; letter-spacing: 4px; font-family: 'Courier New', monospace;">
        {$code}
    </span>
</div>

<p style="color: #6b7280; font-size: 14px;">
    This code expires in 5 minutes.
</p>
HTML;
    
    return sendEmail($email, 'Your Verification Code', buildEmailTemplate('Login Verification', $content));
}
```

### Order/Notification Emails

```php
function sendOrderConfirmation(string $email, string $orderId, array $items): bool
{
    $itemsList = '';
    foreach ($items as $item) {
        $itemsList .= "<tr><td style=\"padding: 8px; border-bottom: 1px solid #e5e7eb;\">{$item['name']}</td>";
        $itemsList .= "<td style=\"padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right;\">\${$item['price']}</td></tr>";
    }
    
    $content = <<<HTML
<p style="margin: 0 0 20px; color: #4b5563;">
    Thank you for your order #<strong>{$orderId}</strong>
</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
    {$itemsList}
</table>
HTML;
    
    return sendEmail($email, "Order Confirmation #{$orderId}", buildEmailTemplate('Order Confirmed', $content));
}
```

## Error Handling

The `sendEmail()` function returns `bool`:
- `true` = Email sent successfully
- `false` = Send failed (check logs in `storage/logs/app.log`)

```php
if (sendEmail($to, $subject, $body)) {
    // Success
} else {
    // Check logs for error details
    write_log("Email failed: {$to}", 'error');
}
```

## Email Template Features

The `buildEmailTemplate()` function provides:

- **Responsive Design** - Works on mobile, tablet, and desktop
- **Brand Colors** - Blue gradient header with professional styling
- **Consistent Styling** - Matches application branding
- **Call-to-Action** - Optional button with link
- **Footer** - Copyright information
- **Charset** - UTF-8 with proper HTML5 doctype

## Testing Email Sending

```bash
# Test email configuration from CLI
php -r "
require_once 'bootstrap.php';
require_once 'src/helpers/email.php';
\$result = sendEmail('your-test@example.com', 'Test', 'This is a test email.');
echo \$result ? 'Sent OK' : 'Failed - check logs';
"
```

Check logs at `storage/logs/app.log` for details.

## Security Considerations

✅ **What's Protected:**
- SMTP credentials stored in `.env` (not in code)
- Emails logged without sensitive content
- App Passwords used for Gmail (not account passwords)
- HTML content escaped properly

⚠️ **What to Be Careful About:**
- Never commit `.env` with real credentials
- Validate email addresses before sending
- Sanitize user content in email body
- Don't send sensitive data (passwords, tokens) unencrypted
- Set `Reply-To` headers when appropriate

## Configuration Variations

### Using a Different Email Provider

Update `.env` variables:

```env
# SendGrid
EMAIL_SMTP_HOST=smtp.sendgrid.net
EMAIL_SMTP_USER=apikey
EMAIL_SMTP_PASS=SG.your_sendgrid_api_key

# AWS SES
EMAIL_SMTP_HOST=email-smtp.us-east-1.amazonaws.com
EMAIL_SMTP_USER=your_smtp_username
EMAIL_SMTP_PASS=your_smtp_password
EMAIL_SMTP_PORT=587

# Mailgun
EMAIL_SMTP_HOST=smtp.mailgun.org
EMAIL_SMTP_USER=postmaster@your-domain.com
EMAIL_SMTP_PASS=your_mailgun_password
```

### Plain Text Emails

```env
EMAIL_MAIL_TYPE=text
```

Then use:
```php
sendEmail($to, $subject, strip_tags($body));
```

## Module Integration Checklist

When adding email to a new module:

- [ ] Email configuration loaded from `.env`
- [ ] Use `sendEmail()` helper function
- [ ] Use `buildEmailTemplate()` for consistent styling
- [ ] Handle send failures gracefully
- [ ] Log errors to `app.log`
- [ ] Test with real SMTP credentials
- [ ] Validate recipient email addresses
- [ ] Sanitize user-provided content in emails

## Related Files

- Email helper: [src/helpers/email.php](src/helpers/email.php)
- Kernel EmailService: [ikabud-kernel/kernel/Services/EmailService.php](ikabud-kernel/kernel/Services/EmailService.php)
- Contact form example: [modules/contact-form/handlers.php](modules/contact-form/handlers.php)
- Environment config: [.env](.env)
