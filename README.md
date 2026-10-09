## Developer

<p>
Neene Kaseba Ned<br>
Developer and Creator of PixelFix Framework
</p>

<p>
Zambia Air Services Training Institute<br>
310198, KK International Airport<br>
Lusaka, Zambia
</p>

<p>
Email: <a href="mailto:neene@zasti.ac.zm">neene@zasti.ac.zm</a><br>
Phone: (+260) 976-032-099 | (+260) 969-889-084
</p>

# PixelFix Installer

The PixelFix Installer is a command-line tool for creating new applications from the public `pixelfix/pixelfix` starter package. It delegates dependency resolution and the starter's post-install setup to Composer.

> Current release: `v0.1.0`, published on Packagist.

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

Choose a published starter version:

```powershell
pixelfix new my-app --version=0.1.18
```

Preview the planned command without requiring Composer to be installed or creating project files:

```powershell
pixelfix new my-app --dry-run
```

The destination must not already exist. Missing parent directories are created recursively when needed, so paths such as `apps/test-app` and `apps\test-app\` are supported. The nearest existing parent must be writable. The installer never overwrites an existing destination. Dry-run previews nested paths without creating directories or files.

## Private framework dependency

The starter package is public, but installing it also requires access to `pixelfix/framework` while the framework repository remains private. Every developer installing the project needs a GitHub account with permission to read that repository and a valid Composer GitHub OAuth credential configured locally.

Configure Composer's GitHub OAuth credential globally using the official [Composer GitHub authentication instructions](https://getcomposer.org/doc/articles/authentication-for-private-packages.md#github-oauth). Composer's setting is `github-oauth.github.com`. Do not commit the token to a repository, add it to `composer.json`, or share it in logs or support messages. Use a token with only the repository read permissions required by the organization and follow any organization approval requirements.

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

The tests cover argument validation, destination safety, version forwarding, dry-run behavior, Composer working-directory selection, and recovery instructions when Composer leaves a partial starter project.

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