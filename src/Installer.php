<?php

declare(strict_types=1);

namespace PixelFix\Installer;

/**
 * Creates PixelFix applications from the published starter project.
 *
 * The installer deliberately delegates the actual project installation to
 * Composer so the starter's own post-install scripts remain the source of
 * truth for environment setup and asset publishing.
 */
final class Installer
{
    /** @var callable(array<string>, string): int */
    private $processRunner;

    /** @var callable(string): void */
    private $writer;

    private string $workingDirectory;

    /**
     * @param callable(array<string>, string): int|null $processRunner Test seam; receives command and working directory.
     * @param callable(string): void|null $writer Test seam for console output.
     */
    public function __construct(?callable $processRunner = null, ?callable $writer = null, ?string $workingDirectory = null)
    {
        $this->processRunner = $processRunner ?? $this->runProcess(...);
        $this->writer = $writer ?? static function (string $message): void {
            fwrite(STDOUT, $message . PHP_EOL);
            fflush(STDOUT);
        };
        $this->workingDirectory = $workingDirectory ?? (getcwd() ?: '.');
    }

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        if ($arguments === [] || in_array($arguments[0], ['--help', '-h', 'help'], true)) {
            $this->printHelp();
            return 0;
        }

        $command = array_shift($arguments);
        if (!in_array($command, ['new', 'create'], true)) {
            ($this->writer)("Unknown command: {$command}");
            ($this->writer)('Run "pixelfix --help" to see available commands.');
            return 2;
        }

        return $this->createProject($arguments);
    }

    /** @param list<string> $arguments */
    private function createProject(array $arguments): int
    {
        $targetInput = null;
        $version = null;
        $dryRun = false;

        for ($index = 0, $count = count($arguments); $index < $count; $index++) {
            $argument = $arguments[$index];

            if ($argument === '--help' || $argument === '-h') {
                $this->printNewHelp();
                return 0;
            }

            if ($argument === '--dry-run') {
                $dryRun = true;
                continue;
            }

            // Accepted for consistency with Composer-based CLI workflows.
            // This command does not prompt for interactive input itself.
            if ($argument === '--no-interaction' || $argument === '-n') {
                continue;
            }

            if ($argument === '--version') {
                if (!isset($arguments[$index + 1]) || str_starts_with($arguments[$index + 1], '--')) {
                    ($this->writer)('The --version option requires a version or constraint.');
                    return 2;
                }
                $version = $arguments[++$index];
                continue;
            }

            if (str_starts_with($argument, '--version=')) {
                $version = substr($argument, strlen('--version='));
                if ($version === '') {
                    ($this->writer)('The --version option requires a version or constraint.');
                    return 2;
                }
                continue;
            }

            if (str_starts_with($argument, '-')) {
                ($this->writer)("Unknown option: {$argument}");
                return 2;
            }

            if ($targetInput !== null) {
                ($this->writer)("Unexpected argument: {$argument}");
                return 2;
            }

            $targetInput = $argument;
        }

        if ($targetInput === null || trim($targetInput) === '') {
            ($this->writer)('Please provide the directory in which to create the application.');
            $this->printNewHelp();
            return 2;
        }

        if ($version !== null && !$this->isSafeVersionConstraint($version)) {
            ($this->writer)("Invalid or unsafe version constraint: {$version}");
            ($this->writer)('Use a Composer version such as 0.1.3, ^0.1, ~0.1.3, or dev-main.');
            return 2;
        }

        if (PHP_VERSION_ID < 80200) {
            ($this->writer)('PixelFix requires PHP 8.2 or later. Current PHP version: ' . PHP_VERSION);
            return 2;
        }

        $target = $this->resolveTarget($targetInput);
        if ($target === null) {
            return 2;
        }

        if (file_exists($target) || is_link($target)) {
            ($this->writer)("The destination already exists; refusing to overwrite it: {$target}");
            ($this->writer)('Choose a new directory name or move the existing path first.');
            return 2;
        }

        $parent = dirname($target);
        $existingParent = $this->nearestExistingDirectory($parent);
        if ($existingParent === null) {
            ($this->writer)("Could not resolve an existing parent directory for: {$target}");
            return 2;
        }

        // Do not rely exclusively on is_writable() here: Windows ACLs and
        // PHP's permission probe can disagree. For a real installation we
        // attempt recursive directory creation and then verify the final
        // parent by creating/removing a uniquely named temporary directory.
        // Dry-run performs no filesystem writes.

        // A dry run must remain useful on machines where Composer is not yet
        // installed. In that case use the generic `composer` command in the
        // preview instead of failing during executable discovery.
        $composerPrefix = $this->resolveComposerPrefix(!$dryRun);
        if ($composerPrefix === null) {
            return 2;
        }

        if ($dryRun) {
            $composerCommand = $this->buildCreateProjectCommand($composerPrefix, $target, $version);
            ($this->writer)('Dry run; no project files or directories will be created.');
            ($this->writer)($this->displayCommand($composerCommand));
            return 0;
        }

        ($this->writer)('Checking Composer...');
        $composerVersionExitCode = ($this->processRunner)([...$composerPrefix, '--version'], $this->workingDirectory);
        if ($composerVersionExitCode !== 0) {
            ($this->writer)('Composer could not be started. Install Composer and ensure it is available on PATH.');
            return 2;
        }

        // Create missing parent directories recursively. The final target
        // directory is deliberately left for Composer's create-project command
        // so the existing destination safety check remains meaningful.
        if (!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)) {
            ($this->writer)("Could not create the parent directory: {$parent}");
            ($this->writer)('Check that the nearest existing parent is writable and that the path is valid.');
            ($this->writer)('If any parent folders were created before the failure, inspect them before retrying.');
            return 2;
        }

        $resolvedParent = realpath($parent);
        if ($resolvedParent === false || !is_dir($resolvedParent)) {
            ($this->writer)("Could not resolve the created parent directory: {$parent}");
            return 2;
        }
        $parent = $resolvedParent;
        $target = $this->joinPath($parent, basename($target));

        // Check again immediately before starting Composer, in case another
        // process created the destination after our first check.
        if (file_exists($target) || is_link($target)) {
            ($this->writer)("The destination was created by another process; refusing to overwrite it: {$target}");
            return 2;
        }

        if (!$this->canCreateDirectoryIn($parent)) {
            ($this->writer)("The destination parent is not writable: {$parent}");
            ($this->writer)('Choose a writable location or adjust its directory permissions.');
            return 2;
        }

        $composerCommand = $this->buildCreateProjectCommand($composerPrefix, $target, $version);

        ($this->writer)('Creating PixelFix application in: ' . $target);
        ($this->writer)('Composer will install the starter and run its configured setup scripts.');

        // Run create-project from the destination's parent directory, not
        // from the installer's own package directory. If the installer has a
        // composer.json there, Composer otherwise treats it as the root
        // package and emits a misleading root-version warning.
        $exitCode = ($this->processRunner)($composerCommand, $parent);
        if ($exitCode !== 0) {
            ($this->writer)('PixelFix application creation failed. Review the Composer output above.');
            ($this->writer)('If Composer cannot download pixelfix/framework, verify that your GitHub account is authorized for the private framework repository and that Composer GitHub OAuth is configured.');
            ($this->writer)('Never commit or share your GitHub token.');

            // Keep partial output to avoid deleting user data or diagnostic
            // evidence. When create-project extracted the starter before a
            // dependency failure, tell the user how to resume installation.
            if (is_file($target . DIRECTORY_SEPARATOR . 'composer.json')) {
                ($this->writer)('A partial starter project exists at: ' . $target);
                ($this->writer)('After correcting the reported issue, resume installation with:');
                ($this->writer)('  cd ' . $this->quoteForDisplay($target));
                ($this->writer)('  composer install');
                ($this->writer)('If you prefer to start over, inspect and remove that directory before running the installer again.');
            } elseif (file_exists($target) || is_link($target)) {
                ($this->writer)('The failed attempt may have left partial files at: ' . $target);
                ($this->writer)('Inspect the directory before removing it or retrying with a different destination.');
            }

            return $exitCode > 0 ? $exitCode : 1;
        }

        ($this->writer)('PixelFix application created successfully.');
        ($this->writer)('Next steps:');
        ($this->writer)('  cd ' . $this->quoteForDisplay($target));
        ($this->writer)('  php pixel help');
        ($this->writer)('  php pixel serve');
        return 0;
    }

    private function resolveTarget(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || in_array($input, ['.', '..'], true)) {
            ($this->writer)('Choose a new directory name; the current or parent directory cannot be the target.');
            return null;
        }

        // Accept both Windows and Unix path separators. Relative paths are
        // interpreted from the directory where the user ran the installer.
        $isWindowsAbsolute = preg_match('~^[A-Za-z]:[\\\\/]~', $input) === 1;
        $isAbsolute = str_starts_with($input, DIRECTORY_SEPARATOR) || $isWindowsAbsolute;
        $candidate = $isAbsolute ? $input : $this->workingDirectory . DIRECTORY_SEPARATOR . $input;
        $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);

        $base = basename($candidate);
        if ($base === '' || $base === '.' || $base === '..' || preg_match('/^[A-Za-z]:$/', $base) === 1) {
            ($this->writer)('The destination must name a new directory, not a filesystem root.');
            return null;
        }

        // Walk upward until an existing directory is found, recording missing
        // path components. This lets `apps/test-app` work even when neither
        // `apps` nor `apps/test-app` exists yet.
        $probe = dirname($candidate);
        $missingComponents = [];

        while (!is_dir($probe)) {
            if (file_exists($probe) || is_link($probe)) {
                ($this->writer)("A parent path component exists but is not a directory: {$probe}");
                return null;
            }

            $component = basename($probe);
            if ($component === '') {
                ($this->writer)('Could not resolve the destination parent path.');
                return null;
            }

            array_unshift($missingComponents, $component);
            $next = dirname($probe);
            if ($next === $probe) {
                ($this->writer)('Could not resolve an existing ancestor for the destination path.');
                return null;
            }
            $probe = $next;
        }

        $resolvedParent = realpath($probe);
        if ($resolvedParent === false) {
            ($this->writer)("Could not resolve the existing ancestor directory: {$probe}");
            return null;
        }

        // Apply unresolved components in order. The `..` handling allows
        // ordinary relative paths such as `some-folder/../my-app` to normalize
        // correctly even when a folder in the path does not yet exist.
        foreach ($missingComponents as $component) {
            if ($component === '' || $component === '.') {
                continue;
            }
            if ($component === '..') {
                $resolvedParent = dirname($resolvedParent);
                continue;
            }
            $resolvedParent = $this->joinPath($resolvedParent, $component);
        }

        $target = $this->joinPath($resolvedParent, $base);
        $working = realpath($this->workingDirectory);
        if ($working !== false && $target === $working) {
            ($this->writer)('The current directory cannot be the installation target.');
            return null;
        }

        return $target;
    }

    private function nearestExistingDirectory(string $path): ?string
    {
        $probe = $path;
        while (!is_dir($probe)) {
            if (file_exists($probe) || is_link($probe)) {
                return null;
            }
            $parent = dirname($probe);
            if ($parent === $probe) {
                return null;
            }
            $probe = $parent;
        }

        $resolved = realpath($probe);
        return $resolved !== false && is_dir($resolved) ? $resolved : null;
    }

    private function joinPath(string $parent, string $child): string
    {
        return rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $child;
    }

    /**
     * Verify real directory-creation access instead of relying only on
     * is_writable(), which can be misleading in some Windows permission setups.
     */
    private function canCreateDirectoryIn(string $parent): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $probe = $this->joinPath(
                $parent,
                '.pixelfix-installer-write-check-' . bin2hex(random_bytes(6))
            );

            if (!@mkdir($probe, 0700)) {
                if (file_exists($probe) || is_link($probe)) {
                    continue;
                }
                return false;
            }

            if (!@rmdir($probe)) {
                ($this->writer)("Could not remove the temporary permission-check directory: {$probe}");
                return false;
            }

            return true;
        }

        return false;
    }

    /** @param list<string> $composerPrefix @return list<string> */
    private function buildCreateProjectCommand(array $composerPrefix, string $target, ?string $version): array
    {
        $command = [...$composerPrefix, 'create-project', 'pixelfix/pixelfix', $target];
        if ($version !== null) {
            $command[] = $version;
        }
        $command[] = '--prefer-dist';
        $command[] = '--no-interaction';
        return $command;
    }

    private function isSafeVersionConstraint(string $version): bool
    {
        // Prevent shell metacharacters and option injection. proc_open receives
        // an argument array, but keeping constraints conservative is clearer.
        return $version !== ''
            && strlen($version) <= 100
            && preg_match('/^[A-Za-z0-9._*^~<>=| -]+$/', $version) === 1
            && !str_starts_with(trim($version), '-');
    }

    /** @param list<string> $command */
    private function displayCommand(array $command): string
    {
        return implode(' ', array_map($this->quoteForDisplay(...), $command));
    }

    private function quoteForDisplay(string $argument): string
    {
        return '"' . str_replace('"', '\\"', $argument) . '"';
    }

    private function printHelp(): void
    {
        ($this->writer)('PixelFix Installer');
        ($this->writer)('');
        ($this->writer)('Usage:');
        ($this->writer)('  pixelfix new <directory> [--version=<constraint>] [--dry-run]');
        ($this->writer)('  pixelfix create <directory> [--version=<constraint>] [--dry-run]');
        ($this->writer)('  pixel new <directory> [--version=<constraint>] [--dry-run]');
        ($this->writer)('  pixel create <directory> [--version=<constraint>] [--dry-run]');
        ($this->writer)('  pixelfix --help | pixel --help');
        ($this->writer)('');
        ($this->writer)('Creates an application from the pixelfix/pixelfix starter package using Composer.');
    }

    private function printNewHelp(): void
    {
        ($this->writer)('Usage: pixelfix create <directory> [options]');
        ($this->writer)('       pixelfix new <directory> [options]');
        ($this->writer)('       pixel create <directory> [options]');
        ($this->writer)('       pixel new <directory> [options]');
        ($this->writer)('');
        ($this->writer)('Options:');
        ($this->writer)('  --version=<constraint>  Select a starter release (for example 0.1.3 or ^0.1).');
        ($this->writer)('  --dry-run               Preview the command without creating directories or files.');
        ($this->writer)('  --no-interaction, -n    Do not prompt for input.');
        ($this->writer)('  --help, -h              Show this help.');
    }


    /**
     * Resolve Composer without requiring a shell command string.
     *
     * Composer's standard Windows launcher is a .bat file. proc_open's array
     * form launches an executable directly, so on Windows prefer the adjacent
     * composer.phar and invoke it through the active PHP binary.
     *
     * @return list<string>|null
     */
    private function resolveComposerPrefix(bool $required = true): ?array
    {
        $configured = getenv('PIXELFIX_COMPOSER_BINARY');
        if (is_string($configured) && $configured !== '') {
            if (strtolower(pathinfo($configured, PATHINFO_EXTENSION)) === 'phar') {
                if (is_file($configured)) {
                    return [PHP_BINARY, $configured];
                }
                if (!$required) {
                    return ['composer'];
                }
                ($this->writer)("PIXELFIX_COMPOSER_BINARY points to a missing file: {$configured}");
                return null;
            }
            if (is_file($configured)) {
                return [$configured];
            }
            if (!$required) {
                return ['composer'];
            }
            ($this->writer)("PIXELFIX_COMPOSER_BINARY points to a missing file: {$configured}");
            return null;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $pathValue = getenv('PATH') ?: '';
            foreach (explode(PATH_SEPARATOR, $pathValue) as $directory) {
                $directory = trim($directory, " \"\t\r\n");
                if ($directory === '') {
                    continue;
                }

                foreach (['composer.phar', 'composer.exe'] as $filename) {
                    $candidate = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . $filename;
                    if (is_file($candidate)) {
                        return strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'phar'
                            ? [PHP_BINARY, $candidate]
                            : [$candidate];
                    }
                }

                // Composer-Setup typically places composer.bat and
                // composer.phar together. Prefer the PHAR when present.
                $launcher = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . 'composer.bat';
                $adjacentPhar = dirname($launcher) . DIRECTORY_SEPARATOR . 'composer.phar';
                if (is_file($launcher) && is_file($adjacentPhar)) {
                    return [PHP_BINARY, $adjacentPhar];
                }
            }

            if (!$required) {
                return ['composer'];
            }

            ($this->writer)('Could not locate composer.phar or composer.exe on PATH. On Windows, set PIXELFIX_COMPOSER_BINARY to the full path of composer.phar if your Composer launcher is a .bat file.');
            return null;
        }

        return ['composer'];
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $workingDirectory): int
    {
        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = @proc_open($command, $descriptors, $pipes, $workingDirectory);
        if (!is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    }
}
