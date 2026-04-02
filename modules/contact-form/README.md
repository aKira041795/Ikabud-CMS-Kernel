# Simple Contact Form Module

A lightweight CMS sub-module that provides a contact form submission endpoint.

## Features

- Simple contact form API endpoint
- Email validation
- Form field validation
- HTML form rendering helper
- JSON response format

## Installation

1. Extract this module to `modules/contact-form/`
2. Upload via CMS admin > Extensions > Modules
3. The module will be automatically enabled upon installation

## Usage

### API Endpoint

**POST** `/api/v1/contact-form/submit`

Request body (JSON):
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "message": "This is my message"
}
```

Response (success):
```json
{
  "ok": true,
  "message": "Thank you for your message. We will get back to you soon.",
  "submission": {
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

### Form Rendering

Use the helper function in your theme:

```php
echo contactFormRender([
    'id' => 'contact-form',
    'showLabels' => true
]);
```

## Author

Test Author

## Version

1.0.0
