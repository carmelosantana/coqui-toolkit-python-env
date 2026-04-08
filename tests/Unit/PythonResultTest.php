<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tests\Unit;

use CoquiBot\Toolkits\PythonEnv\Runtime\PythonResult;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

test('succeeded returns true for exit code 0', function () {
    $result = new PythonResult(0, 'output', '');

    expect($result->succeeded())->toBeTrue();
});

test('succeeded returns false for non-zero exit code', function () {
    $result = new PythonResult(1, '', 'error');

    expect($result->succeeded())->toBeFalse();
});

test('output combines stdout and stderr', function () {
    $result = new PythonResult(0, 'hello', 'warning');

    expect($result->output())->toBe("hello\nwarning");
});

test('output returns only stdout when stderr is empty', function () {
    $result = new PythonResult(0, 'hello', '');

    expect($result->output())->toBe('hello');
});

test('output returns only stderr when stdout is empty', function () {
    $result = new PythonResult(1, '', 'error message');

    expect($result->output())->toBe('error message');
});

test('output returns empty string when both are empty', function () {
    $result = new PythonResult(0, '', '');

    expect($result->output())->toBe('');
});

test('toToolResult returns success for exit code 0', function () {
    $result = new PythonResult(0, 'done', '');
    $toolResult = $result->toToolResult();

    expect($toolResult)->toBeInstanceOf(ToolResult::class);
    expect($toolResult->content)->toBe('done');
});

test('toToolResult returns error for non-zero exit code', function () {
    $result = new PythonResult(1, '', 'fail');
    $toolResult = $result->toToolResult();

    expect($toolResult)->toBeInstanceOf(ToolResult::class);
    expect($toolResult->content)->toBe('fail');
});

test('toToolResult returns default message when output is empty and succeeded', function () {
    $result = new PythonResult(0, '', '');
    $toolResult = $result->toToolResult();

    expect($toolResult->content)->toBe('Command completed successfully.');
});

test('toToolResult returns default message when output is empty and failed', function () {
    $result = new PythonResult(1, '', '');
    $toolResult = $result->toToolResult();

    expect($toolResult->content)->toBe('Command failed with no output.');
});

test('json decodes valid JSON stdout', function () {
    $data = [['name' => 'numpy', 'version' => '1.26.0']];
    $result = new PythonResult(0, json_encode($data), '');

    expect($result->json())->toBe($data);
});

test('json returns null for invalid JSON', function () {
    $result = new PythonResult(0, 'not json', '');

    expect($result->json())->toBeNull();
});

test('json returns null for empty stdout', function () {
    $result = new PythonResult(0, '', '');

    expect($result->json())->toBeNull();
});

test('json returns null for non-array JSON', function () {
    $result = new PythonResult(0, '"just a string"', '');

    expect($result->json())->toBeNull();
});
