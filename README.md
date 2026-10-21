# PixelFix Installer

The PixelFix Installer is a command-line tool for creating new applications from the public `pixelfix/pixelfix` starter package. It delegates dependency resolution and the starter's post-install setup to Composer.

## Requirements

- PHP 8.2 or later.
- Composer 2.x available on `PATH` for real installations.
- Network access to Packagist and any repositories required by the starter.
- Authorized access to `PixelFixFramework/framework` while that framework repository remains private.

## Install the CLI

Install the published package globally from Packagist:

```powershell
composer global require pixelfix/installer
pixelfix --help
pixel --help  # equivalent executable alias
```

Make sure Composer's global executable directory is on `PATH` if PowerShell cannot find `pixelfix` or `pixel`. Find the directory with `composer global config bin-dir --absolute`, add that directory to your Windows user `PATH`, and open a new PowerShell window.

To update an existing global installation after a new installer release is published, run:

```powershell
composer global update pixelfix/installer
```

## Create an application

```powershell
pixelfix new my-app
# Equivalent commands:
pixelfix create my-app
pixel new my-app
pixel create my-app

# Missing parent folders are created automatically:
pixelfix create apps\test-app\
pixel create apps\another-app\
```

After creating your project, change into it and start the application:

```powershell
cd my-app
php pixel help
php pixel serve
```

Preview the planned command without requiring Composer to be installed or creating project files:

```powershell
pixelfix new my-app --dry-run
```

For interactive installation, the default behavior, Composer can prompt for GitHub credentials when a private framework dependency requires authentication. In automated environments where credentials are already configured, suppress prompts explicitly:

```powershell
pixelfix new my-app --no-interaction
# or
pixel create my-app -n
```

The destination must not already exist. Missing parent directories are created recursively when needed, so paths such as `apps/test-app` and `apps\test-app\` are supported. The nearest existing parent must be writable. The installer never overwrites an existing destination. Dry-run previews nested paths without creating directories or files.

## Private framework dependency

The starter package is public, but installing it also requires access to `pixelfix/framework` while the framework repository remains private. Every developer installing the project needs a GitHub account with permission to read that repository and a valid Composer GitHub OAuth credential configured locally. The installer keeps Composer interactive by default so Composer can request credentials when required. Use `--no-interaction` only in automated environments where the required credentials have already been configured.

Configure Composer's GitHub OAuth credential globally using the official [Composer GitHub authentication instructions](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth). Composer's setting is `github-oauth.github.com`. Do not commit the token to a repository, add it to `composer.json`, or share it in logs or support messages. Use a token with only the repository read permissions required by the organization and follow any organization approval requirements.

### Troubleshooting GitHub protocol and SSH errors

If project creation fails with an error such as `Host key verification failed`, and the Composer output shows it trying to clone an address like `git@github.com:PixelFixFramework/framework.git`, check Composer's GitHub protocol configuration. The `github-protocols` setting controls the protocols Composer may use for GitHub clones, in priority order.

Display the currently configured value (the setting name is plural: `github-protocols`):

```powershell
composer config --global github-protocols
```

If SSH fallback is enabled, configure Composer to use HTTPS only:

```powershell
composer config --global github-protocols https
```

This is a global Composer setting and affects other Composer projects for the current Windows user as well. It does **not** create a GitHub token, grant repository permissions, or fix an account that is not authorized to read the private framework repository. Keep a valid Composer GitHub OAuth credential configured and use an account that has access to `PixelFixFramework/framework`.

To remove this override and return to Composer's default protocol selection, run:

```powershell
composer config --global --unset github-protocols
```

Composer's documented default is `https`, `ssh`, and `git` in priority order; the plain `git` protocol is only used when secure HTTP is disabled. See the [Composer `github-protocols` configuration reference](https://getcomposer.org/doc/06-config.md#github-protocols).

If Composer continues attempting a `git@github.com:` clone after HTTPS-only mode is set, inspect the repository URL in the starter's `composer.json` and any Git URL rewrite rules before changing credentials:

```powershell
git config --show-origin --get-regexp "url\..*\.insteadof"
```

No output means that command found no configured Git URL rewrite rules at the Git configuration scopes it inspected. A `git@github.com:` clone can still originate from repository/source metadata, so verify the actual URL Composer is trying to clone. Do not disable host-key verification or share your token to troubleshoot this issue.

If project creation fails after Composer has extracted the starter, the installer preserves that partial directory rather than deleting diagnostic files. After correcting the reported issue, enter the partial project and run:

```powershell
composer install
```

The starter's configured Composer scripts should then create `.env` and publish frontend assets. If you prefer a clean retry, inspect the partial directory and remove it yourself before running `pixelfix new` or `pixelfix create` with that destination again.

## Local development and tests

Run the standalone tests without installing dependencies:

```powershell
php tests/run.php
```

The tests cover argument validation, destination safety, version forwarding, interactive authentication by default, explicit non-interactive mode, dry-run behavior, Composer working-directory selection, and recovery instructions when Composer leaves a partial starter project.

To run the test suite through Composer in this package:

```powershell
composer test
```

## Release checklist

Before publishing a new tagged release:

1. Review `composer.json`, the license declaration, README, and tests.
2. Run `composer validate --strict`.
3. Run `composer test` and `php tests/run.php`.
4. Verify both the `pixelfix` and `pixel` executable aliases on Windows and Linux.
5. Test a new application from a clean target directory using a GitHub account authorized for the private framework.
6. Commit and push the reviewed changes, update the changelog, and create a new version tag.
7. Confirm Packagist imports the new tag, then test `composer global update pixelfix/installer` from an installed v0.1.0 environment.

The installer currently creates a project only. A browser-based application deployment wizard (database credentials, migrations, initial administrator account, and deployment checks) would be a separate feature in the starter application.
