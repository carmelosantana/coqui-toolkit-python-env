<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

/**
 * Lists installed packages in a Python virtual environment.
 *
 * Supports multiple output formats and can show outdated packages
 * to help diagnose version conflicts.
 */
final readonly class ListTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_list',
            description: 'List installed Python packages in a virtual environment. Can show package versions in table, freeze, or JSON format, and optionally filter to show only outdated packages.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new EnumParameter(
                    name: 'format',
                    description: 'Output format: table (human-readable), freeze (pip freeze output for requirements.txt), json (machine-readable)',
                    values: ['table', 'freeze', 'json'],
                ),
                new BoolParameter('outdated', 'Only show packages that have newer versions available'),
                new StringParameter('filter', 'Filter packages by name (case-insensitive substring match)'),
                new StringParameter('env_name', 'Name of the environment directory (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->list(
                projectPath: $args['project_path'] ?? '',
                format: $args['format'] ?? 'table',
                outdated: (bool) ($args['outdated'] ?? false),
                filter: $args['filter'] ?? '',
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function list(
        string $projectPath,
        string $format,
        bool $outdated,
        string $filter,
        string $envName,
    ): ToolResult {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        if (!$this->runner->venvExists($projectPath, $envName)) {
            return ToolResult::error(
                "No virtual environment at {$projectPath}/{$envName}. Create one first with python_env_create.",
            );
        }

        if ($format === 'freeze') {
            return $this->pipFreeze($projectPath, $envName, $filter);
        }

        $args = ['list', '--format=json'];
        if ($outdated) {
            $args[] = '--outdated';
        }

        $result = $this->runner->runPip($projectPath, $args, $envName, 30);
        if (!$result->succeeded()) {
            return $result->toToolResult();
        }

        $packages = $result->json();
        if ($packages === null) {
            return ToolResult::error('Failed to parse pip list output.');
        }

        // Apply filter
        if ($filter !== '') {
            $filterLower = strtolower($filter);
            $packages = array_values(array_filter(
                $packages,
                fn(mixed $pkg): bool => is_array($pkg) && str_contains(
                    strtolower((string) ($pkg['name'] ?? '')),
                    $filterLower,
                ),
            ));
        }

        if ($packages === []) {
            $msg = $outdated ? 'All packages are up to date.' : 'No packages installed.';
            if ($filter !== '') {
                $msg = "No packages matching \"{$filter}\" found.";
            }
            return ToolResult::success($msg);
        }

        if ($format === 'json') {
            return ToolResult::success(json_encode($packages, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        // Table format
        /** @var list<array<string, mixed>> $packageList */
        $packageList = array_values($packages);
        return ToolResult::success($this->formatTable($packageList, $outdated));
    }

    private function pipFreeze(string $projectPath, string $envName, string $filter): ToolResult
    {
        $result = $this->runner->runPip($projectPath, ['freeze'], $envName, 10);
        if (!$result->succeeded()) {
            return $result->toToolResult();
        }

        $output = trim($result->stdout);
        if ($output === '') {
            return ToolResult::success('No packages installed.');
        }

        if ($filter !== '') {
            $filterLower = strtolower($filter);
            $lines = array_filter(
                explode("\n", $output),
                fn(string $line): bool => str_contains(strtolower($line), $filterLower),
            );
            $output = implode("\n", $lines);
            if ($output === '') {
                return ToolResult::success("No packages matching \"{$filter}\" found.");
            }
        }

        return ToolResult::success($output);
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function formatTable(array $packages, bool $outdated): string
    {
        $lines = [];

        if ($outdated) {
            $lines[] = sprintf('%-30s %-15s %-15s', 'Package', 'Current', 'Latest');
            $lines[] = str_repeat('-', 62);
            foreach ($packages as $pkg) {
                $lines[] = sprintf(
                    '%-30s %-15s %-15s',
                    (string) ($pkg['name'] ?? ''),
                    (string) ($pkg['version'] ?? ''),
                    (string) ($pkg['latest_version'] ?? ''),
                );
            }
        } else {
            $lines[] = sprintf('%-30s %-15s', 'Package', 'Version');
            $lines[] = str_repeat('-', 47);
            foreach ($packages as $pkg) {
                $lines[] = sprintf(
                    '%-30s %-15s',
                    (string) ($pkg['name'] ?? ''),
                    (string) ($pkg['version'] ?? ''),
                );
            }
        }

        $lines[] = '';
        $lines[] = 'Total: ' . count($packages) . ' package(s)';

        return implode("\n", $lines);
    }
}
