# Email System Setup - Quick Reference

## Status: ✅ Configured and Ready

This document confirms that email credentials and system have been set up for the application.

## Configuration Applied

### Environment Variables (.env)
```
EMAIL_PROTOCOL=smtp
EMAIL_SMTP_HOST=smtp.gmail.com
EMAIL_SMTP_PORT=587
EMAIL_SMTP_USER=your-email@gmail.com
EMAIL_SMTP_PASS=your-gmail-app-password
EMAIL_SMTP_CRYPTO=tls
EMAIL_FROM_EMAIL=noreply@yourdomain.com
EMAIL_FROM_NAME="Ikabud Kernel"
EMAIL_MAIL_TYPE=html
EMAIL_CHARSET=utf-8
```

### Helper Function
**Location:** `src/helpers/email.php`

**Available Functions:**
- `sendEmail($to, $subject, $body, $options)` - Send email
- `buildEmailTemplate($title, $content, $ctaText, $ctaUrl)` - Create HTML template

**Loaded in:** `public/index.php`

## Current Implementation

### Contact Form Module
- ✅ Sends HTML-formatted submission emails
- ✅ Uses `buildEmailTemplate()` for professional styling
- ✅ Includes sender information in email body
- ✅ Logs send results to `storage/logs/app.log`

**File:** `modules/contact-form/handlers.php`

## Usage Example

```php
// Simple email
sendEmail('user@example.com', 'Welcome', 'Hello world!');

// Formatted email with template
$content = '<p>Thank you for signing up!</p>';
$body = buildEmailTemplate('Welcome', $content, 'Get Started', 'https://example.com');
sendEmail('user@example.com', 'Welcome!', $body);
```

## Available Modules Ready to Use Email

1. **Contact Form** - ✅ Implemented
2. **Password Reset** - Template provided in docs
3. **Two-Factor Auth** - Template provided in docs
4. **Order Notifications** - Example in docs
5. **Custom Modules** - Can use `sendEmail()` function

## Important Notes

⚠️ **SMTP Authentication:**
- Gmail requires App Passwords (not account password)
- Credentials are in `.env` (not committed to repo)
- If email fails, check `storage/logs/app.log`

🔒 **Security:**
- Email credentials should never be in code
- Always validate recipient emails before sending
- Use proper HTML escaping in templates
- Set Reply-To header when appropriate

## Testing Email

```bash
# From command line
cd /var/www/html/ikabud
php -r "
require_once 'bootstrap.php';
require_once 'src/helpers/email.php';
\$result = sendEmail('test@example.com', 'Test', 'Hello!');
echo \$result ? 'Sent' : 'Failed';
"
```

Check logs: `storage/logs/app.log`

## Documentation

- Full guide: `docs/EMAIL_CONFIGURATION.md`
- Contact form example: `modules/contact-form/handlers.php`
- Helper functions: `src/helpers/email.php`

## Troubleshooting

**Email not sending?**
1. Check `storage/logs/app.log` for errors
2. Verify credentials in `.env`
3. Confirm Gmail App Password is set
4. Test from CLI: `php -r "..."`

**Module doesn't use email yet?**
1. Include `sendEmail()` in module handler
2. Use `buildEmailTemplate()` for HTML
3. Handle failures gracefully
4. Log results: `write_log($message, 'error')`

---

**Last Updated:** 2026-03-17
**Configuration Status:** Active & Functional
