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
