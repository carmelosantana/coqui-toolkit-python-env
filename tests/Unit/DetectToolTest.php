<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tests\Unit;

use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;
use CoquiBot\Toolkits\PythonEnv\Tool\DetectTool;

test('detect tool identifies requirements.txt', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/requirements.txt', "numpy\npandas\n");

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('requirements.txt');
    expect($result->content)->toContain('Pip requirements file');

    // Cleanup
    unlink($tmpDir . '/requirements.txt');
    rmdir($tmpDir);
});

test('detect tool identifies pyproject.toml', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/pyproject.toml', "[project]\nname = \"test\"\n");

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('pyproject.toml');
    expect($result->content)->toContain('PEP 621');

    // Cleanup
    unlink($tmpDir . '/pyproject.toml');
    rmdir($tmpDir);
});

test('detect tool identifies environment.yml for conda', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/environment.yml', "name: test\ndependencies:\n  - numpy\n");

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('environment.yml');
    expect($result->content)->toContain('Conda environment file');
    expect($result->content)->toContain('conda');

    // Cleanup
    unlink($tmpDir . '/environment.yml');
    rmdir($tmpDir);
});

test('detect tool reports no dependency files for empty directory', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-empty-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('None found');

    // Cleanup
    rmdir($tmpDir);
});

test('detect tool includes system tools section', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-sys-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('System Tools');
    expect($result->content)->toContain('python');

    // Cleanup
    rmdir($tmpDir);
});

test('detect tool includes recommended next steps', function () {
    $tmpDir = sys_get_temp_dir() . '/python-env-test-detect-steps-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/requirements.txt', "numpy\n");

    $runner = new PythonRunner();
    $tool = (new DetectTool($runner))->build();
    $result = $tool->execute(['project_path' => $tmpDir]);

    expect($result->content)->toContain('Recommended Next Steps');
    expect($result->content)->toContain('python_env_create');

    // Cleanup
    unlink($tmpDir . '/requirements.txt');
    rmdir($tmpDir);
});
