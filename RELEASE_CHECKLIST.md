# PixelFix Installer Release Checklist

## Package validation

- [ ] Confirm package name is `pixelfix/installer`.
- [ ] Confirm `bin/pixelfix` and `bin/pixel` are listed under Composer `bin` and have PHP CLI shebangs.
- [ ] Confirm the license declaration accurately represents intended distribution.
- [ ] Run `composer validate`.
- [ ] Run `composer test` and `php tests/run.php`.
- [ ] Confirm no secrets, local paths, test archives, or Composer auth files are committed.

## Integration verification

- [ ] Run `pixelfix --help` after a global Composer installation.
- [ ] Verify `pixelfix new sample-app --dry-run` and `pixel create apps/sample-app --dry-run` preview nested paths without creating directories or files and do not require Composer to be executable.
- [ ] Verify a successful create-project run executes Composer from the target parent directory.
- [ ] Verify existing destinations are rejected and missing parent directories are created recursively.
- [ ] Verify failure after starter extraction prints recovery instructions and preserves the partial project.
- [ ] Verify Composer is interactive by default so it can request private-repository credentials; confirm `--no-interaction` is forwarded only when explicitly supplied.
- [ ] Test both `pixelfix` and `pixel` aliases on Windows PowerShell and a Unix-like shell.
- [ ] Test with a GitHub account that is authorized to read the private framework repository.
- [ ] Verify the normal install remains interactive so Composer can prompt for private-repository credentials.
- [ ] Verify `--no-interaction` is passed to Composer only when explicitly requested.

## Publication

- [ ] Create the installer GitHub repository in the PixelFix organization.
- [ ] Push the reviewed source and tests.
- [ ] Tag a release (for example, `v0.1.0`) and push the tag.
- [ ] Register/submit the repository on Packagist as `pixelfix/installer`.
- [ ] Test `composer global require pixelfix/installer` from a clean environment.
- [ ] Update the starter README and installation guides with the published command.
