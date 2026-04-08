<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tests\Unit;

use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

test('resolveSystemPython finds a python binary', function () {
    $runner = new PythonRunner();
    $python = $runner->resolveSystemPython();

    // On any dev machine, python3 should be available
    expect($python)->not->toBeEmpty();
    expect(is_executable($python))->toBeTrue();
});

test('resolveVenvPython returns empty for non-existent path', function () {
    $runner = new PythonRunner();
    $result = $runner->resolveVenvPython('/nonexistent/project', '.venv');

    expect($result)->toBe('');
});

test('venvExists returns false for non-existent venv', function () {
    $runner = new PythonRunner();

    expect($runner->venvExists('/nonexistent/project'))->toBeFalse();
});

test('buildEnv sets VIRTUAL_ENV and prepends PATH', function () {
    $runner = new PythonRunner();
    $env = $runner->buildEnv('/tmp/testproject', '.venv');

    expect($env['VIRTUAL_ENV'])->toBe('/tmp/testproject/.venv');
    expect(str_starts_with($env['PATH'], '/tmp/testproject/.venv/bin:'))->toBeTrue();
    expect(array_key_exists('PYTHONHOME', $env))->toBeFalse();
});

test('buildEnv adds src to PYTHONPATH when src dir exists', function () {
    // Create a temp project with src/ dir
    $tmpDir = sys_get_temp_dir() . '/python-env-test-' . uniqid();
    mkdir($tmpDir . '/src', 0755, true);

    $runner = new PythonRunner();
    $env = $runner->buildEnv($tmpDir, '.venv');

    expect(str_contains($env['PYTHONPATH'] ?? '', $tmpDir . '/src'))->toBeTrue();

    // Cleanup
    rmdir($tmpDir . '/src');
    rmdir($tmpDir);
});

test('createVenv creates a virtual environment', function () {
    $runner = new PythonRunner();
    $python = $runner->resolveSystemPython();

    if ($python === '') {
        $this->markTestSkipped('No system Python available');
    }

    $tmpDir = sys_get_temp_dir() . '/python-env-test-create-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $result = $runner->createVenv($tmpDir, '.venv', 60);

    expect($result->succeeded())->toBeTrue();
    expect($runner->venvExists($tmpDir, '.venv'))->toBeTrue();

    // Cleanup
    exec('rm -rf ' . escapeshellarg($tmpDir));
})->group('integration');

test('runPythonCode executes inline code in venv', function () {
    $runner = new PythonRunner();
    $python = $runner->resolveSystemPython();

    if ($python === '') {
        $this->markTestSkipped('No system Python available');
    }

    $tmpDir = sys_get_temp_dir() . '/python-env-test-run-' . uniqid();
    mkdir($tmpDir, 0755, true);

    // Create venv first
    $createResult = $runner->createVenv($tmpDir, '.venv', 60);
    if (!$createResult->succeeded()) {
        exec('rm -rf ' . escapeshellarg($tmpDir));
        $this->markTestSkipped('Could not create venv: ' . $createResult->output());
    }

    $result = $runner->runPythonCode($tmpDir, 'print(2 + 2)', '.venv', 10);

    expect($result->succeeded())->toBeTrue();
    expect(trim($result->stdout))->toBe('4');

    // Cleanup
    exec('rm -rf ' . escapeshellarg($tmpDir));
})->group('integration');
