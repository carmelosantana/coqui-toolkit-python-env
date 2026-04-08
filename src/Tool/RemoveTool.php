<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

/**
 * Removes a Python virtual environment from a project directory.
 *
 * Handles venv, conda prefix environments, and uv environments.
 * Requires explicit confirmation to prevent accidental deletion.
 */
final readonly class RemoveTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_remove',
            description: 'Remove a Python virtual environment from a project directory. Permanently deletes the environment directory and all installed packages. Requires confirm=true.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new BoolParameter('confirm', 'Must be set to true to confirm deletion — this is destructive and irreversible'),
                new StringParameter('env_name', 'Name of the environment directory to remove (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->remove(
                projectPath: $args['project_path'] ?? '',
                confirm: (bool) ($args['confirm'] ?? false),
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function remove(
        string $projectPath,
        bool $confirm,
        string $envName,
    ): ToolResult {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        if (!$confirm) {
            return ToolResult::error(
                'Deletion requires confirm=true. This will permanently remove the virtual environment and all installed packages.',
            );
        }

        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        if (!is_dir($envPath)) {
            return ToolResult::error("No environment found at {$envPath}.");
        }

        // Safety: refuse to delete if envName contains path traversal or is a root directory
        $realEnvPath = realpath($envPath);
        $realProjectPath = realpath($projectPath);
        if ($realEnvPath === false || $realProjectPath === false) {
            return ToolResult::error("Cannot resolve path: {$envPath}");
        }

        if (!str_starts_with($realEnvPath, $realProjectPath . '/')) {
            return ToolResult::error("Safety check failed: {$envPath} is not inside the project directory.");
        }

        // Check for conda prefix environment
        $condaBin = $this->runner->resolveConda();
        $isCondaEnv = is_file($envPath . '/conda-meta/history');

        if ($isCondaEnv && $condaBin !== '') {
            $result = $this->runner->runConda(
                ['env', 'remove', '-p', $realEnvPath, '-y'],
                $projectPath,
                PythonRunner::DEFAULT_TIMEOUT,
            );
            if ($result->succeeded()) {
                return ToolResult::success("Conda environment removed: {$envPath}");
            }
            // Fall through to manual removal if conda command fails
        }

        // Remove directory tree (venv or uv environment)
        $result = $this->runner->runShell(
            $projectPath,
            'rm -rf ' . escapeshellarg($realEnvPath),
            null,
            PythonRunner::DEFAULT_TIMEOUT,
        );

        if (!$result->succeeded()) {
            return ToolResult::error("Failed to remove {$envPath}:\n" . $result->output());
        }

        return ToolResult::success(
            "Virtual environment removed: {$envPath}\n\n"
            . "To create a new one, use python_env_create.",
        );
    }
}
