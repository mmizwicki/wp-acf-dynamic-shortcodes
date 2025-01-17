# Changelog

All notable changes to the Dynamic Shortcodes Manager plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1] - 2025-01-17

### Added

- Support for shortcodes in WordPress navigation menus
  - Menu items (full menu HTML)
  - Menu item titles
  - Menu item descriptions
  - Menu item title attributes (hover text)

## [1.0] - 2025-01-06

### Added

- Initial release
- ACF Options page for managing shortcodes
- Repeater field for creating multiple shortcodes
- Auto-slugification of shortcode names
- Real-time shortcode preview
- Security features:
  - Sanitization of shortcode output
  - Prevention of PHP code execution
  - Script injection protection
  - Nonce verification for admin actions
- Support for shortcodes in:
  - Post/page titles
  - Post/page content
  - Admin area titles
  - Yoast SEO titles and descriptions
  - Gravity Forms fields (conditional processing)
