# Release Notes - 2026-03-27

## Summary

This batch stabilizes CMS public rendering, storefront CTA correctness, ecommerce admin order rendering, admin-side cache invalidation, platform URL generation, tenant routing guards, and WordPress import portability.

## Included Changes

- Fixed CMS storefront CTA rendering so in-stock products consistently render an enabled Add to Cart action.
- Fixed ecommerce admin order detail addresses to use hydrated billing and shipping objects instead of raw template expressions.
- Documented the current CMS public page-loading conventions and added an implementation guide for future CMS work.
- Hardened external URL generation so CMS, SEO, AI automation, ticketing, and importer/admin flows respect the current request host instead of stale configured URLs.
- Added reconnect helpers and response-finishing utilities to reduce post-idle failures and unblock long-running follow-up work after responses are flushed.
- Cached CMS admin dashboard, content, media, users, settings, and menu renders, with targeted invalidation on writes.
- Extracted CMS import/export helper logic into a dedicated helper file and rewrote imported WordPress internal URLs to the current site base URL during WXR parsing.
- Added tenant fast-reject routing guards for common probe paths and throttled repeated workflow definition seeding.

## Verification

- CMS cache regression suite passes.
- Targeted tenancy router regression coverage now includes fast-reject probe cases.
- Targeted WordPress importer regression coverage now verifies internal URL rewriting during WXR parsing.