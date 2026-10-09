# Changelog

All notable changes to the PixelFix Installer will be documented here.

## Unreleased — release candidate hardening

- Run Composer `create-project` from the requested destination's parent directory so the installer's own `composer.json` is not detected as the root package.
- Let `--dry-run` display the planned command without invoking or requiring Composer.
- Report actionable recovery steps when Composer leaves a partial starter project after a failed installation.
- Expand documentation for private-framework authentication, local testing, and publication checks.
- Add regression coverage for Composer working-directory selection and dry-run behavior.
