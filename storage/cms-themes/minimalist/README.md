# Minimalist Theme

A clean, minimal theme with a focus on readability and simplicity.

## Features

- Clean, responsive design
- Supports multiple page templates:
  - **Landing Page** — Hero section with call-to-action
  - **Default Page** — Simple page layout
  - **Single Post** — Blog post layout with metadata
  - **With Right Sidebar** — Page with optional right sidebar
- Default home page and blog archive layouts
- Custom styling with CSS variables
- Smooth scrolling and form handling with JavaScript
- Mobile-responsive design
- Support for primary navigation menu
- Light color scheme with good readability

## Installation

1. Extract this theme to `storage/cms-themes/minimalist/`
2. Upload via CMS admin > Extensions > Themes
3. Activate the theme from the Theme Manager

## Customization

### Colors

Edit CSS variables in `style.css`:

```css
:root {
    --color-primary: #333;
    --color-secondary: #666;
    --color-background: #fff;
    --color-border: #eee;
}
```

### Templates

Choose page templates from the editor:
- **Landing Page** — Full-width hero layout
- **With Right Sidebar** — Two-column layout

## Template Files

- `layouts/public.disyl` — Main layout wrapper
- `public/home.disyl` — Home page
- `public/page.disyl` — Static pages
- `public/single.disyl` — Single post view
- `public/landing.disyl` — Landing page template
- `public/sidebar-right.disyl` — Page with right sidebar

## Assets

- `style.css` — Stylesheet
- `script.js` — JavaScript interactions

## Author

Test Theme Author

## Version

1.0.0
