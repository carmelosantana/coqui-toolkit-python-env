<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

/**
 * Runs Python code, scripts, or modules inside a project's virtual environment.
 *
 * Useful for verifying installations, running tests, executing one-off scripts,
 * or running module commands (e.g., -m pytest, -m mypackage).
 */
final readonly class RunTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_run',
            description: 'Run Python code, a script, or a module inside a virtual environment. Use type "code" for inline Python, "script" for .py files, "module" for python -m commands.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new StringParameter('command', 'The Python code, script path, or module name to run', required: true),
                new EnumParameter(
                    name: 'type',
                    description: 'Execution type: code (inline Python via -c), script (.py file path), module (python -m <name>)',
                    values: ['code', 'script', 'module'],
                ),
                new StringParameter('args', 'Space-separated arguments to pass to the script or module'),
                new StringParameter('env_name', 'Name of the environment directory (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->run(
                projectPath: $args['project_path'] ?? '',
                command: $args['command'] ?? '',
                type: $args['type'] ?? 'code',
                runArgs: $args['args'] ?? '',
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function run(
        string $projectPath,
        string $command,
        string $type,
        string $runArgs,
        string $envName,
    ): ToolResult {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        if ($command === '') {
            return ToolResult::error('The command parameter is required.');
        }

        if (!$this->runner->venvExists($projectPath, $envName)) {
            return ToolResult::error(
                "No virtual environment at {$projectPath}/{$envName}. Create one first with python_env_create.",
            );
        }

        $argsList = $runArgs !== '' ? preg_split('/\s+/', trim($runArgs)) ?: [] : [];

        $result = match ($type) {
            'script' => $this->runner->runScript($projectPath, $command, $argsList, $envName, PythonRunner::INSTALL_TIMEOUT),
            'module' => $this->runner->runModule($projectPath, $command, $argsList, $envName, PythonRunner::INSTALL_TIMEOUT),
            default => $this->runner->runPythonCode($projectPath, $command, $envName, PythonRunner::DEFAULT_TIMEOUT),
        };

        return $result->toToolResult();
    }
}
