# PixelFix Installer Release Checklist

## Package validation

- [ ] Confirm package name is `pixelfix/installer`.
- [ ] Confirm `bin/pixelfix` is listed under Composer `bin` and has a PHP CLI shebang.
- [ ] Confirm the license declaration accurately represents intended distribution.
- [ ] Run `composer validate`.
- [ ] Run `composer test` and `php tests/run.php`.
- [ ] Confirm no secrets, local paths, test archives, or Composer auth files are committed.

## Integration verification

- [ ] Run `pixelfix --help` after a global Composer installation.
- [ ] Verify `pixelfix new sample-app --dry-run` creates no files and does not require Composer to be executable.
- [ ] Verify a successful create-project run executes Composer from the target parent directory.
- [ ] Verify existing destinations are rejected.
- [ ] Verify failure after starter extraction prints recovery instructions and preserves the partial project.
- [ ] Test on Windows PowerShell and a Unix-like shell.
- [ ] Test with a GitHub account that is authorized to read the private framework repository.

## Publication

- [ ] Create the installer GitHub repository in the PixelFix organization.
- [ ] Push the reviewed source and tests.
- [ ] Tag a release (for example, `v0.1.0`) and push the tag.
- [ ] Register/submit the repository on Packagist as `pixelfix/installer`.
- [ ] Test `composer global require pixelfix/installer` from a clean environment.
- [ ] Update the starter README and installation guides with the published command.
