# Production Security Checklist

## Install And Bootstrap

- Ensure `storage/.installed` exists after provisioning.
- Keep the web installer locked. `public/lock.php` should return `403` on installed systems.
- Delete `public/lock.php` from production once installation and smoke checks are complete.
- Verify `.env` is not web-accessible and contains a strong `JWT_SECRET`.
- Set `APP_ENV=production` and `APP_DEBUG=0`.

## Transport And Cookies

- Serve the app only over HTTPS.
- Confirm auth cookies are issued with `HttpOnly`, `Secure`, and `SameSite=Strict` or the strictest mode your deployment allows.
- Enable HSTS on the production hostname once HTTPS is stable.
- Keep `APP_URL` aligned with the canonical HTTPS origin.

## Auth And Account Recovery

- Rotate `JWT_SECRET` if credentials or database snapshots are ever exposed.
- Review `refresh_tokens` regularly and purge revoked or expired rows.
- Confirm logout revokes outstanding refresh tokens for kernel users.
- Test password-reset flow with SMTP configured and verify rate limiting returns `429` under abuse.
- Remove or tightly restrict any test email endpoints in production workflows.

## Uploads And Extensions

- Allow theme/module installation only for trusted admins.
- Keep CSRF protection enabled on all admin upload and module-management routes.
- Retain ZIP validation, upload-origin checks, and archive size limits.
- Keep media upload limits conservative and review allowed MIME types periodically.
- Do not install third-party modules or themes without reviewing their manifests and code.

## Data And Database

- Use database users with only the privileges the app needs.
- Protect control-plane database credentials and `CONTROL_DB_ENC_KEY` with the same rigor as production secrets.
- If database TLS is enabled, keep `DB_SSL_VERIFY_SERVER_CERT` and `CONTROL_DB_SSL_VERIFY_SERVER_CERT` enabled and ensure the configured CA path is deployed with the app.
- Back up both tenant and control-plane databases before running upgrades or importing large data sets.
- Validate that multi-tenant deployments reject cross-tenant JWTs and do not fall back to global settings unexpectedly.

## HTTP And Integration Surface

- Set `CORS_ORIGINS` to an explicit allowlist only.
- Audit any endpoint that accepts `Authorization` headers or refresh tokens from external clients.
- Review installed modules for routes that mutate state without `app()->csrfEnforce()`.
- Keep external API keys out of logs and avoid echoing provider errors directly to clients.

## Logging And Monitoring

- Review [storage/logs/app.log](/var/www/html/applicationostest/storage/logs/app.log) and [storage/logs/error.log](/var/www/html/applicationostest/storage/logs/error.log) after deployments and security-sensitive changes.
- Correlate incidents with `X-Request-Id` values.
- Alert on repeated failed login, password-reset, import, and module-install attempts.
- Treat recurring installer-audit warnings as a deployment misconfiguration, not normal noise.

## Release Verification

- Run `php scripts/test-install-http.php` after deployment.
- Run targeted syntax checks on modified PHP files with `php -l`.
- Re-test admin flows for module upload, theme upload, import/export, login, logout, and password reset.
- Confirm expected redirects or `403` responses instead of generic `500` pages.

## Ecommerce & License Activation

- Verify that the `rate_limits` table exists in each tenant DB before go-live; digital checkout auto-registration (`checkout_register` action) is non-fatal if the table is missing in development, but must be enforced in production.
- Confirm that `ecAutoRegisterGuestAsCustomer()` clamps `$_SESSION['cms_user_role']` to `['customer', 'subscriber']` — a buyer's existing elevated CMS role must never carry over into a purchase session.
- After rotating the ecommerce RS256 private key, regenerate all outstanding license JWTs; old licenses remain valid until their `exp` claim, but new purchases from the old key cannot be re-verified once `license-key.pem` is updated.
- Ensure `modules/guidance/license-key.pem` contains the **public** key only and is never committed with or derived from the private key.
- When testing license activation over HTTP (non-HTTPS), the `navigator.clipboard` API is blocked; verify that the `document.execCommand('copy')` fallback path in `ecCopyLicenseKey()` is exercised so admins can copy full JWTs from the order detail page.
- Review `guidanceLicenseJtiTenantBound()` scope when adding new freemium modules: each module needs its own JTI replay check keyed to its own settings field, not shared with guidance.