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
 * Creates a new Python virtual environment in the project directory.
 *
 * Supports three backends: venv (standard library), conda, and uv (fast alternative).
 * After creation, pip is automatically upgraded to the latest version (venv backend).
 */
final readonly class CreateTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_create',
            description: 'Create a new Python virtual environment inside a project directory. Supports venv (default), conda, and uv backends. Creates the environment at <project_path>/<env_name>.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new EnumParameter(
                    name: 'backend',
                    description: 'Environment manager to use: venv (standard, most compatible), conda (for scientific computing, specific Python versions), uv (fastest, modern pip replacement)',
                    values: ['venv', 'conda', 'uv'],
                ),
                new StringParameter('python_version', 'Python version to use (e.g., "3.11", "3.12"). Only applies to conda and uv backends. venv uses the system Python version.'),
                new StringParameter('env_name', 'Name of the environment directory (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->create(
                projectPath: $args['project_path'] ?? '',
                backend: $args['backend'] ?? 'venv',
                pythonVersion: $args['python_version'] ?? '',
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function create(
        string $projectPath,
        string $backend,
        string $pythonVersion,
        string $envName,
    ): ToolResult {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        // Check if environment already exists
        if ($this->runner->venvExists($projectPath, $envName)) {
            return ToolResult::error(
                "A virtual environment already exists at {$envPath}. "
                . 'Remove it first with python_env_remove if you want to recreate it.',
            );
        }

        return match ($backend) {
            'conda' => $this->createConda($projectPath, $envName, $pythonVersion),
            'uv' => $this->createUv($projectPath, $envName, $pythonVersion),
            default => $this->createVenv($projectPath, $envName),
        };
    }

    private function createVenv(string $projectPath, string $envName): ToolResult
    {
        $result = $this->runner->createVenv($projectPath, $envName);
        if (!$result->succeeded()) {
            return ToolResult::error(
                "Failed to create virtual environment.\n"
                . $result->output() . "\n\n"
                . "Tips:\n"
                . "- Ensure Python 3.9+ is installed: python3 --version\n"
                . "- On Debian/Ubuntu, you may need: sudo apt install python3-venv\n"
                . "- On macOS, the Xcode command line tools should include venv support",
            );
        }

        // Upgrade pip in the new environment
        $pipUpgrade = $this->runner->runPip(
            $projectPath,
            ['install', '--upgrade', 'pip'],
            $envName,
            PythonRunner::INSTALL_TIMEOUT,
        );

        $envPath = rtrim($projectPath, '/') . '/' . $envName;
        $output = "Virtual environment created at {$envPath}\n";

        if ($pipUpgrade->succeeded()) {
            $output .= "pip upgraded to latest version.\n";
        }

        $output .= "\nNext steps:\n";
        $output .= "- Install dependencies: use python_env_install\n";
        $output .= "- Check status: use python_env_status";

        return ToolResult::success($output);
    }

    private function createConda(string $projectPath, string $envName, string $pythonVersion): ToolResult
    {
        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        $args = ['create', '-p', $envPath, '-y'];
        if ($pythonVersion !== '') {
            $args[] = "python={$pythonVersion}";
        } else {
            $args[] = 'python';
        }

        $result = $this->runner->runConda($args, $projectPath, PythonRunner::INSTALL_TIMEOUT);
        if (!$result->succeeded()) {
            return ToolResult::error(
                "Failed to create conda environment.\n"
                . $result->output() . "\n\n"
                . "Tips:\n"
                . "- Ensure conda is installed: conda --version\n"
                . "- Install Miniconda: https://docs.conda.io/en/latest/miniconda.html",
            );
        }

        return ToolResult::success(
            "Conda environment created at {$envPath}\n"
            . ($pythonVersion !== '' ? "Python version: {$pythonVersion}\n" : '')
            . "\nNext steps:\n"
            . "- Install dependencies: use python_env_install with method 'conda'\n"
            . "- Check status: use python_env_status",
        );
    }

    private function createUv(string $projectPath, string $envName, string $pythonVersion): ToolResult
    {
        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        $args = ['venv', $envPath];
        if ($pythonVersion !== '') {
            $args[] = '--python';
            $args[] = $pythonVersion;
        }

        $result = $this->runner->runUv($projectPath, $args);
        if (!$result->succeeded()) {
            return ToolResult::error(
                "Failed to create uv environment.\n"
                . $result->output() . "\n\n"
                . "Tips:\n"
                . "- Ensure uv is installed: uv --version\n"
                . "- Install with: curl -LsSf https://astral.sh/uv/install.sh | sh",
            );
        }

        return ToolResult::success(
            "Virtual environment created at {$envPath} (via uv)\n"
            . ($pythonVersion !== '' ? "Python version: {$pythonVersion}\n" : '')
            . "\nNext steps:\n"
            . "- Install dependencies: use python_env_install with method 'uv'\n"
            . "- Check status: use python_env_status",
        );
    }
}
