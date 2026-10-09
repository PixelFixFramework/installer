# Changelog

All notable changes to the PixelFix Installer are documented here.

## [0.1.1] - Unreleased

### Added
- Create missing parent directories recursively for nested project paths.
- Provide `pixel` as an executable alias alongside `pixelfix`.
- Document `new` and `create` with both executable names.

### Changed
- Dry-run explicitly reports that no files or directories are created.
- Update README examples and publication guidance for the already-published v0.1.0 package.

### Safety
- Use recursive directory creation for missing parents and verify write access with a real directory-creation probe (rather than relying only on `is_writable()`).
- Re-check the final destination immediately before invoking Composer.

## [0.1.0] - 2026-10-09

### Added
- Initial public release of the PixelFix Installer CLI.
- Create PixelFix applications from `pixelfix/pixelfix` using Composer.
- Support `new` and `create` subcommands, version selection, and dry-run preview.
- Preserve partial projects and show recovery guidance when Composer fails.
