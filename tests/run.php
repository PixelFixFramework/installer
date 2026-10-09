<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Installer.php';

use PixelFix\Installer\Installer;

$passed = 0;
$failed = 0;

function test(string $name, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function temporaryDirectory(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pixelfix-installer-' . bin2hex(random_bytes(5));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create temporary directory.');
    }
    return $path;
}

function cleanupDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

test('help returns success and displays usage', function (): void {
    $output = [];
    $installer = new Installer(
        static fn(array $command, string $cwd): int => 0,
        static function (string $line) use (&$output): void { $output[] = $line; }
    );
    expect($installer->run(['--help']) === 0, 'Help should return zero.');
    expect(str_contains(implode("\n", $output), 'pixelfix new <directory>'), 'Usage should be printed.');
});

test('missing destination fails without running commands', function (): void {
    $calls = 0;
    $output = [];
    $installer = new Installer(
        static function (array $command, string $cwd) use (&$calls): int { $calls++; return 0; },
        static function (string $line) use (&$output): void { $output[] = $line; }
    );
    expect($installer->run(['new']) === 2, 'Missing destination should fail.');
    expect($calls === 0, 'Composer must not run for invalid input.');
});

test('existing destination is never overwritten', function (): void {
    $cwd = temporaryDirectory();
    try {
        mkdir($cwd . DIRECTORY_SEPARATOR . 'already-there');
        $calls = 0;
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int { $calls++; return 0; },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );
        expect($installer->run(['new', 'already-there']) === 2, 'Existing directory should fail.');
        expect($calls === 0, 'Composer must not run when destination exists.');
        expect(str_contains(implode("\n", $output), 'refusing to overwrite'), 'Refusal should be explained.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('invalid version constraints are rejected', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = 0;
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int { $calls++; return 0; },
            static function (string $line): void {},
            $cwd
        );
        expect($installer->run(['new', 'my-app', '--version=0.1.3;whoami']) === 2, 'Unsafe constraint should fail.');
        expect($calls === 0, 'Composer must not run for unsafe version constraints.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('creates a project through Composer with the requested version', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = [];
        $output = [];
        $runner = static function (array $command, string $workingDirectory) use (&$calls): int {
            $calls[] = $command;
            return 0;
        };
        $installer = new Installer($runner, static function (string $line) use (&$output): void { $output[] = $line; }, $cwd);
        expect($installer->run(['new', 'my-app', '--version=0.1.3']) === 0, 'Successful Composer exit should succeed.');
        expect(count($calls) === 2, 'Should check Composer and then create project.');
        expect(end($calls[0]) === '--version', 'Composer availability should be checked first.');
        $createProjectIndex = array_search('create-project', $calls[1], true);
        expect($createProjectIndex !== false, 'Should call create-project.');
        expect(($calls[1][$createProjectIndex + 1] ?? null) === 'pixelfix/pixelfix', 'Should install the published starter.');
        expect(($calls[1][$createProjectIndex + 3] ?? null) === '0.1.3', 'Requested version should be forwarded.');
        expect(in_array('--no-interaction', $calls[1], true), 'No-interaction should be enabled for deterministic execution.');
        expect(str_contains(implode("\n", $output), 'created successfully'), 'Success guidance should be printed.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('dry run previews the command without requiring or running Composer', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = [];
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int { $calls[] = $command; return 0; },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );
        expect($installer->run(['new', 'my-app', '--dry-run']) === 0, 'Dry run should succeed.');
        expect(count($calls) === 0, 'Dry run must not execute Composer, including the version check.');
        $display = implode("\n", $output);
        expect(str_contains($display, 'Dry run'), 'Dry run should be stated.');
        expect(str_contains($display, 'create-project'), 'Dry run should display the planned create-project command.');
        expect(!file_exists($cwd . DIRECTORY_SEPARATOR . 'my-app'), 'Dry run must not create the target.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('create-project runs from destination parent, not installer package directory', function (): void {
    $root = temporaryDirectory();
    try {
        $installerDirectory = $root . DIRECTORY_SEPARATOR . 'installer';
        $projectsDirectory = $root . DIRECTORY_SEPARATOR . 'projects';
        mkdir($installerDirectory);
        mkdir($projectsDirectory);
        file_put_contents($installerDirectory . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"pixelfix/installer"}');

        $calls = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int {
                $calls[] = ['command' => $command, 'cwd' => $workingDirectory];
                return 0;
            },
            static function (string $line): void {},
            $installerDirectory
        );

        expect($installer->run(['new', $projectsDirectory . DIRECTORY_SEPARATOR . 'sandbox-app']) === 0, 'Project creation should succeed.');
        expect(count($calls) === 2, 'Composer version check and project creation should run.');
        expect($calls[0]['cwd'] === $installerDirectory, 'Composer version check can run from the installer directory.');
        expect($calls[1]['cwd'] === realpath($projectsDirectory), 'create-project must run from the target parent directory.');
        expect($calls[1]['cwd'] !== $installerDirectory, 'The installer package must not be treated as Composer root.');
    } finally {
        cleanupDirectory($root);
    }
});

test('creates missing parent directories for nested destinations', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = [];
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int {
                $calls[] = ['command' => $command, 'cwd' => $workingDirectory];
                return 0;
            },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );

        $destinationArgument = 'apps\\test-app\\';
        expect($installer->run(['create', $destinationArgument]) === 0, 'Nested project creation should succeed.');
        $appsDirectory = $cwd . DIRECTORY_SEPARATOR . 'apps';
        $expectedTarget = $appsDirectory . DIRECTORY_SEPARATOR . 'test-app';

        expect(is_dir($appsDirectory), 'Missing parent directory should be created recursively.');
        expect(count($calls) === 2, 'Composer version check and create-project should run.');
        expect($calls[1]['cwd'] === realpath($appsDirectory), 'Composer must run from the newly created destination parent.');
        $createProjectIndex = array_search('create-project', $calls[1]['command'], true);
        expect($createProjectIndex !== false, 'create-project should be invoked.');
        expect(($calls[1]['command'][$createProjectIndex + 2] ?? null) === $expectedTarget, 'Trailing separators should be normalized from the target path.');
        expect(!file_exists($expectedTarget), 'The installer should leave the final app directory to Composer.');
        expect(str_contains(implode("\n", $output), 'created successfully'), 'Successful completion guidance should be printed.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('dry-run previews nested destinations without creating parent directories', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = [];
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int { $calls[] = $command; return 0; },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );

        expect($installer->run(['create', 'apps\\test-app\\', '--dry-run']) === 0, 'Nested dry-run should succeed.');
        expect($calls === [], 'Dry-run must not invoke Composer.');
        expect(!is_dir($cwd . DIRECTORY_SEPARATOR . 'apps'), 'Dry-run must not create missing parent directories.');
        expect(str_contains(implode("\n", $output), 'no project files or directories will be created'), 'Dry-run should explain that it makes no filesystem changes.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('registers both pixelfix and pixel executable aliases', function (): void {
    $packageRoot = dirname(__DIR__);
    $composer = json_decode((string) file_get_contents($packageRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect(($composer['bin'] ?? null) === ['bin/pixelfix', 'bin/pixel'], 'Composer should install both CLI aliases.');

    foreach (['pixelfix', 'pixel'] as $alias) {
        $executable = $packageRoot . '/bin/' . $alias;
        expect(is_file($executable), "The {$alias} executable should exist.");
        $output = [];
        $status = 1;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($executable) . ' --help 2>&1';
        exec($command, $output, $status);
        expect($status === 0, "The {$alias} executable should display help successfully.");
        expect(str_contains(implode("\n", $output), 'pixel create <directory>'), "The {$alias} help should document the alias syntax.");
    }
});

test('rejects a file used as a parent path component', function (): void {
    $cwd = temporaryDirectory();
    try {
        file_put_contents($cwd . DIRECTORY_SEPARATOR . 'not-a-directory', 'x');
        $calls = 0;
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int { $calls++; return 0; },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );

        expect($installer->run(['create', 'not-a-directory' . DIRECTORY_SEPARATOR . 'app']) === 2, 'A file must not be accepted as a parent directory.');
        expect($calls === 0, 'Composer must not run when a path component is a file.');
        expect(str_contains(implode("\n", $output), 'not a directory'), 'The invalid parent component should be explained.');
    } finally {
        cleanupDirectory($cwd);
    }
});

test('Composer failure gives recovery steps when a partial starter exists', function (): void {
    $cwd = temporaryDirectory();
    try {
        $calls = [];
        $output = [];
        $installer = new Installer(
            static function (array $command, string $workingDirectory) use (&$calls): int {
                $calls[] = $command;
                $createProjectIndex = array_search('create-project', $command, true);
                if ($createProjectIndex !== false) {
                    $target = $command[$createProjectIndex + 2];
                    mkdir($target, 0777, true);
                    file_put_contents($target . DIRECTORY_SEPARATOR . 'composer.json', '{"name":"pixelfix/pixelfix"}');
                    return 1;
                }
                return 0;
            },
            static function (string $line) use (&$output): void { $output[] = $line; },
            $cwd
        );
        expect($installer->run(['new', 'my-app']) === 1, 'Composer failure should be returned.');
        $display = implode("\n", $output);
        expect(str_contains($display, 'GitHub account is authorized'), 'Authentication guidance should be printed.');
        expect(str_contains($display, 'composer install'), 'Partial project recovery command should be printed.');
        expect(str_contains($display, 'partial starter project exists'), 'Partial project should be acknowledged.');
        expect(is_file($cwd . DIRECTORY_SEPARATOR . 'my-app' . DIRECTORY_SEPARATOR . 'composer.json'), 'Partial project should be preserved for recovery.');
    } finally {
        cleanupDirectory($cwd);
    }
});

echo "\nTests: " . ($passed + $failed) . ", Passed: {$passed}, Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
