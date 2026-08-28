# Changelog

All notable changes to `bladepdf/php` will be documented in this file.

## 1.0.0 - 2026-08-25

- Initial framework-agnostic BladePDF PHP SDK.
- Synchronous and asynchronous HTML and cloud-template rendering.
- Permission-scoped local asset discovery for HTML, CSS, fonts, images, JavaScript files, and external SVG files, including recursive and cycle-safe CSS rewriting.
- Canonical root enforcement against traversal and symlink escapes, deterministic asset naming, deduplication, and explicit caller-approved overrides.
- Typed render results, submissions, exceptions, and webhook verification.
