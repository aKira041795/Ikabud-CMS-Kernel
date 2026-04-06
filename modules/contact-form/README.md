# Advanced Contact Form Module

A lightweight yet powerful CMS sub-module that provides a modern, responsive contact form submission endpoint with comprehensive field type support and automated validation.

## Features

- **Extensive Field Types**: Support for `text`, `email`, `tel`, `number`, `textarea`, `select`, `multiselect`, `radio`, `checkbox`, `consent` (GDPR), `rating`, `range`, `color`, and `file` uploads.
- **File Uploads**: Secure file handling via `cmsValidateMediaUploadFile` with automatic directory organization and tenant isolation.
- **Dynamic & Legacy Forms**: Create advanced dynamic forms via the builder or fall back to traditional `name`, `email`, `message` payloads.
- **UI / UX Improvements**: Modern glassmorphic focus rings, elegant button transitions, responsive spacing, and accessible ARIA attributes.
- **Spam Protection**: Built-in honeypot support and optional CAPTCHA integration.
- **JSON & FormData Support**: API gracefully accepts both JSON payloads and HTTP `multipart/form-data` (required for file uploads).

## Installation

1. Place the module in `modules/contact-form/`
2. Enable via CMS Admin > Settings > Modules
3. The schema and migrations will run automatically upon first render or sync via `contactFormSchemaStatus()`.

## Usage

### Form Rendering

Use the provided render helper in your Display Templates (Disyl) or PHP themes:

```php
echo contactFormRender([
    'id'              => 'custom-contact-form',
    'title'           => 'Get in Touch',
    'submit_label'    => 'Send Message',
    'success_message' => 'Thanks for reaching out! We will reply shortly.'
]);
```
Alternatively, render a specific dynamic form from the builder:
```php
echo contactFormRenderDynamic($savedFormId, ['id' => 'my-form']);
```

### API Endpoint

**POST** `/api/v1/contact-form/submit`

To support file attachments, forms are submitted using native `FormData` (`multipart/form-data` encoding). 

Example Request (Javascript):
```javascript
const form = document.getElementById('contact-form');
const formData = new FormData(form);

fetch('/api/v1/contact-form/submit', {
    method: 'POST',
    body: formData
}).then(res => res.json());
```

Response (Success):
```json
{
  "ok": true,
  "message": "Thank you for your message.",
  "redirect_url": ""
}
```

Response (Validation Error):
```json
{
  "ok": false,
  "error": "Please check the form below and try again.",
  "field_errors": {
    "email": "Please enter a valid email for Email.",
    "resume": "File exceeds the maximum allowed size of 2MB."
  }
}
```

## Security & Architecture

- **Tenant Isolation**: Static caches and database queries securely scope to the active tenant ID using `app()->tenant()->current()`.
- **Media Validation**: File uploads are scanned using the Kernel Media tools avoiding path traversal, excessive sizes, and restricted execution signatures.
- **XSS Prevention**: Field rendering automatically escapes labels, options, and help text using `contactFormEscape()`.

## Version

1.1.0
