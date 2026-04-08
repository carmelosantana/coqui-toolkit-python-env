<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tests\Unit;

use CoquiBot\Toolkits\PythonEnv\PythonEnvToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;

test('PythonEnvToolkit implements ToolkitInterface', function () {
    $toolkit = new PythonEnvToolkit();

    expect($toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

test('toolkit returns 7 tools', function () {
    $toolkit = new PythonEnvToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(7);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(ToolInterface::class);
    }
});

test('toolkit tools have expected names', function () {
    $toolkit = new PythonEnvToolkit();
    $tools = $toolkit->tools();

    $names = array_map(fn(ToolInterface $tool): string => $tool->name(), $tools);

    expect($names)->toBe([
        'python_env_detect',
        'python_env_create',
        'python_env_status',
        'python_env_install',
        'python_env_list',
        'python_env_run',
        'python_env_remove',
    ]);
});

test('toolkit guidelines contain setup workflow', function () {
    $toolkit = new PythonEnvToolkit();
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toContain('python_env_detect');
    expect($guidelines)->toContain('python_env_create');
    expect($guidelines)->toContain('python_env_install');
    expect($guidelines)->toContain('venv');
    expect($guidelines)->toContain('conda');
    expect($guidelines)->toContain('uv');
});

test('detect tool rejects non-existent directory', function () {
    $toolkit = new PythonEnvToolkit();
    $tools = $toolkit->tools();
    $detectTool = $tools[0];

    $result = $detectTool->execute(['project_path' => '/nonexistent/path']);

    expect($result->content)->toContain('not found');
});
