<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function emailEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value === false && isset($_ENV[$key])) {
        $value = (string)$_ENV[$key];
    }

    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return $default;
    }

    if (strlen($value) >= 2) {
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }

    return $value;
}

function emailNormalizeSmtpPassword(string $host, string $password): string
{
    $host = strtolower(trim($host));
    if ($password === '') {
        return '';
    }

    // Gmail app passwords are commonly displayed in 4-character groups with spaces,
    // but SMTP authentication expects the raw 16-character token.
    if ($host === 'smtp.gmail.com' || $host === 'smtp.googlemail.com') {
        return preg_replace('/\s+/', '', $password) ?? $password;
    }

    return $password;
}

/**
 * Send an email using configured SMTP settings
 * 
 * Available for all modules (contact-form, forgot-password, etc.)
 * Uses PHPMailer with SMTP for reliable email delivery.
 *
 * @param string $to          Recipient email address
 * @param string $subject     Email subject
 * @param string $body        Email body (HTML or text based on EMAIL_MAIL_TYPE)
 * @param array  $options     Optional: ['cc' => [...], 'bcc' => [...], 'reply_to' => 'email@domain.com']
 * @return bool              True on success, false on failure
 */
function sendEmail(string $to, string $subject, string $body, array $options = []): bool
{
    $mail = null;
    try {
        // Validate recipient email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            write_log("Email validation failed: Invalid email address '$to'", 'error');
            return false;
        }

        // Null/log mailer: tests and ephemeral environments must never send
        // real mail. EMAIL_MAILER=log|null|test skips SMTP entirely, records a
        // debug line, and reports success so flows (forgot-password, etc.)
        // behave normally without hitting a real SMTP server.
        $mailerMode = strtolower(emailEnv('EMAIL_MAILER', 'smtp'));
        if (in_array($mailerMode, ['null', 'log', 'test', 'array'], true)) {
            write_log("Email [$mailerMode]: to=$to, subject=$subject", 'debug');
            return true;
        }
        
        // Create PHPMailer instance
        $mail = new PHPMailer(true);
        
        $protocol = strtolower(emailEnv('EMAIL_PROTOCOL', 'smtp'));
        if ($protocol !== 'smtp') {
            write_log("Email protocol '$protocol' is not supported by sendEmail(); expected smtp", 'error');
            return false;
        }

        // SMTP configuration from environment
        $mail->isSMTP();
        $mail->Host = emailEnv('EMAIL_SMTP_HOST', 'smtp.gmail.com');
        $mail->Port = (int)emailEnv('EMAIL_SMTP_PORT', '587');
        $mail->Username = emailEnv('EMAIL_SMTP_USER');
        $mail->Password = emailNormalizeSmtpPassword($mail->Host, emailEnv('EMAIL_SMTP_PASS'));
        $mail->SMTPAuth = ($mail->Username !== '' && $mail->Password !== '');

        $crypto = strtolower(emailEnv('EMAIL_SMTP_CRYPTO', 'tls'));
        if ($crypto === 'ssl' || $crypto === 'smtps') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($crypto === 'tls' || $crypto === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        
        // Mail settings
        $mailType = strtolower(emailEnv('EMAIL_MAIL_TYPE', 'html'));
        $mail->isHTML($mailType === 'html');
        $mail->CharSet = emailEnv('EMAIL_CHARSET', 'utf-8');
        
        // From address
        $fromEmail = emailEnv('EMAIL_FROM_EMAIL', 'noreply@ikabudkernel.com');
        $fromName = emailEnv('EMAIL_FROM_NAME', 'Ikabud Kernel');
        $mail->setFrom($fromEmail, $fromName);
        
        // Recipients
        $mail->addAddress($to);
        
        // CC if provided
        if (!empty($options['cc']) && is_array($options['cc'])) {
            foreach ($options['cc'] as $cc) {
                if (filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                    $mail->addCC($cc);
                }
            }
        }
        
        // BCC if provided
        if (!empty($options['bcc']) && is_array($options['bcc'])) {
            foreach ($options['bcc'] as $bcc) {
                if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) {
                    $mail->addBCC($bcc);
                }
            }
        }
        
        // Reply-To header
        $replyTo = $options['reply_to'] ?? $fromEmail;
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }
        
        // Subject and body
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);
        
        // Log email config (without sensitive data)
        write_log("Sending email via SMTP: to=$to, host=" . $mail->Host . ":" . $mail->Port . ", subject=$subject", 'debug');
        
        // Send email
        $mail->send();
        write_log("Email sent successfully: to=$to, subject=$subject", 'info');
        return true;
        
    } catch (MailException $e) {
        $errorMsg = $e->getMessage();
        if ($mail) {
            $errorMsg = "SMTP error: {$mail->ErrorInfo}";
        }
        write_log("Email send failed: to=$to, subject=$subject, error=$errorMsg", 'error');
        return false;
    } catch (Throwable $e) {
        write_log("Email service exception: " . $e->getMessage() . " | " . $e->getFile() . ":" . $e->getLine(), 'error');
        return false;
    }
}

/**
 * Build a professional HTML email template
 * 
 * Use this to wrap your email content in a consistent branded template
 *
 * @param string $title       Email title/headline
 * @param string $content     HTML content body
 * @param string $ctaText     Optional: Call-to-action button text
 * @param string $ctaUrl      Optional: Call-to-action button URL
 * @return string            Complete HTML email template
 */
function buildEmailTemplate(string $title, string $content, string $ctaText = '', string $ctaUrl = ''): string
{
    $cta = '';
    if ($ctaText !== '' && $ctaUrl !== '') {
        $cta = <<<HTML
                            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td align="center" style="padding: 20px 0;">
                                        <a href="{$ctaUrl}" target="_blank" style="display: inline-block; padding: 12px 36px; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 14px;">
                                            {$ctaText}
                                        </a>
                                    </td>
                                </tr>
                            </table>
HTML;
    }
    
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f7fa;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 0;">
                <table role="presentation" style="width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border-radius: 8px 8px 0 0;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 600;">{$title}</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            {$content}
                            {$cta}
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 40px; text-align: center; border-top: 1px solid #e5e7eb; background-color: #f9fafb;">
                            <p style="margin: 0; color: #6b7280; font-size: 12px; line-height: 1.6;">
                                © 2026 Ikabud Kernel. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

