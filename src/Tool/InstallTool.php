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
 * Install Python packages into a virtual environment.
 *
 * Supports installing from: individual packages, requirements.txt files,
 * pyproject.toml extras, and conda environment files. Works with pip, conda,
 * and uv backends. Handles special cases like CUDA PyTorch index URLs.
 */
final readonly class InstallTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_install',
            description: 'Install Python packages into a virtual environment. Supports pip, conda, and uv. Can install individual packages, from requirements.txt, or from pyproject.toml extras.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new EnumParameter(
                    name: 'method',
                    description: 'Package manager to use: pip (standard), conda (scientific computing), uv (fast modern alternative)',
                    values: ['pip', 'conda', 'uv'],
                ),
                new StringParameter('packages', 'Space-separated list of packages to install (e.g., "numpy pandas scipy"). Ignored if requirements_file or extras is set.'),
                new StringParameter('requirements_file', 'Path to a requirements file relative to the project (e.g., "requirements.txt", "requirements-dev.txt")'),
                new StringParameter('extras', 'Install the project itself with extras from pyproject.toml (e.g., "dev", "cuda", "dev,cuda"). Uses pip install -e ".[extras]" syntax.'),
                new StringParameter('index_url', 'Custom package index URL (e.g., "https://download.pytorch.org/whl/cu130" for CUDA PyTorch). Used with --extra-index-url for pip/uv.'),
                new StringParameter('env_name', 'Name of the environment directory (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->install(
                projectPath: $args['project_path'] ?? '',
                method: $args['method'] ?? 'pip',
                packages: $args['packages'] ?? '',
                requirementsFile: $args['requirements_file'] ?? '',
                extras: $args['extras'] ?? '',
                indexUrl: $args['index_url'] ?? '',
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function install(
        string $projectPath,
        string $method,
        string $packages,
        string $requirementsFile,
        string $extras,
        string $indexUrl,
        string $envName,
    ): ToolResult {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        // At least one install source must be specified
        if ($packages === '' && $requirementsFile === '' && $extras === '') {
            return ToolResult::error(
                "Specify at least one of: 'packages' (e.g., \"numpy pandas\"), "
                . "'requirements_file' (e.g., \"requirements.txt\"), "
                . "or 'extras' (e.g., \"dev\" for pyproject.toml extras).",
            );
        }

        return match ($method) {
            'conda' => $this->installConda($projectPath, $packages, $requirementsFile, $envName),
            'uv' => $this->installUv($projectPath, $packages, $requirementsFile, $extras, $indexUrl, $envName),
            default => $this->installPip($projectPath, $packages, $requirementsFile, $extras, $indexUrl, $envName),
        };
    }

    private function installPip(
        string $projectPath,
        string $packages,
        string $requirementsFile,
        string $extras,
        string $indexUrl,
        string $envName,
    ): ToolResult {
        if (!$this->runner->venvExists($projectPath, $envName)) {
            return ToolResult::error(
                "No virtual environment at {$projectPath}/{$envName}. Create one first with python_env_create.",
            );
        }

        $args = ['install'];

        // Add index URL if specified
        if ($indexUrl !== '') {
            $args[] = '--extra-index-url';
            $args[] = $indexUrl;
        }

        // Determine what to install
        if ($extras !== '') {
            // Install project itself with extras: pip install -e ".[dev,cuda]"
            $args[] = '-e';
            $args[] = ".[{$extras}]";
        } elseif ($requirementsFile !== '') {
            $reqPath = $this->resolveRequirementsPath($projectPath, $requirementsFile);
            if ($reqPath === '') {
                return ToolResult::error("Requirements file not found: {$requirementsFile}");
            }
            $args[] = '-r';
            $args[] = $reqPath;
        } else {
            // Individual packages
            foreach (preg_split('/\s+/', trim($packages)) ?: [] as $pkg) {
                if ($pkg !== '') {
                    $args[] = $pkg;
                }
            }
        }

        $result = $this->runner->runPip($projectPath, $args, $envName, PythonRunner::INSTALL_TIMEOUT);

        if (!$result->succeeded()) {
            return $this->formatInstallError($result->output(), 'pip');
        }

        return ToolResult::success($this->summarizeInstallOutput($result->stdout, 'pip'));
    }

    private function installConda(
        string $projectPath,
        string $packages,
        string $requirementsFile,
        string $envName,
    ): ToolResult {
        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        $args = ['install', '-p', $envPath, '-y'];

        if ($requirementsFile !== '') {
            $reqPath = $this->resolveRequirementsPath($projectPath, $requirementsFile);
            if ($reqPath === '') {
                return ToolResult::error("Requirements file not found: {$requirementsFile}");
            }
            $args[] = '--file';
            $args[] = $reqPath;
        } elseif ($packages !== '') {
            foreach (preg_split('/\s+/', trim($packages)) ?: [] as $pkg) {
                if ($pkg !== '') {
                    $args[] = $pkg;
                }
            }
        } else {
            return ToolResult::error('Conda does not support pyproject.toml extras. Use "packages" or "requirements_file" instead.');
        }

        $result = $this->runner->runConda($args, $projectPath, PythonRunner::INSTALL_TIMEOUT);

        if (!$result->succeeded()) {
            return $this->formatInstallError($result->output(), 'conda');
        }

        return ToolResult::success($this->summarizeInstallOutput($result->stdout, 'conda'));
    }

    private function installUv(
        string $projectPath,
        string $packages,
        string $requirementsFile,
        string $extras,
        string $indexUrl,
        string $envName,
    ): ToolResult {
        $envPath = rtrim($projectPath, '/') . '/' . $envName;

        $args = ['pip', 'install', '--python', $envPath . '/bin/python'];

        if ($indexUrl !== '') {
            $args[] = '--extra-index-url';
            $args[] = $indexUrl;
        }

        if ($extras !== '') {
            $args[] = '-e';
            $args[] = ".[{$extras}]";
        } elseif ($requirementsFile !== '') {
            $reqPath = $this->resolveRequirementsPath($projectPath, $requirementsFile);
            if ($reqPath === '') {
                return ToolResult::error("Requirements file not found: {$requirementsFile}");
            }
            $args[] = '-r';
            $args[] = $reqPath;
        } else {
            foreach (preg_split('/\s+/', trim($packages)) ?: [] as $pkg) {
                if ($pkg !== '') {
                    $args[] = $pkg;
                }
            }
        }

        $result = $this->runner->runUv($projectPath, $args, PythonRunner::INSTALL_TIMEOUT);

        if (!$result->succeeded()) {
            return $this->formatInstallError($result->output(), 'uv');
        }

        return ToolResult::success($this->summarizeInstallOutput($result->stdout, 'uv'));
    }

    /**
     * Resolve a requirements file path (absolute or relative to project).
     */
    private function resolveRequirementsPath(string $projectPath, string $file): string
    {
        // Already absolute
        if (str_starts_with($file, '/')) {
            return is_file($file) ? $file : '';
        }

        $path = rtrim($projectPath, '/') . '/' . $file;
        return is_file($path) ? $path : '';
    }

    /**
     * Format common install errors with troubleshooting tips.
     */
    private function formatInstallError(string $output, string $method): ToolResult
    {
        $tips = ["Installation failed ({$method}):\n", $output, "\nTroubleshooting tips:"];

        if (str_contains($output, 'Could not find a version') || str_contains($output, 'No matching distribution')) {
            $tips[] = '- Check package name spelling';
            $tips[] = '- The package may not support your Python version';
            $tips[] = '- Try upgrading pip: python_env_install with packages "pip --upgrade"';
        }

        if (str_contains($output, 'Torch not compiled with CUDA')) {
            $tips[] = '- Install CUDA-compatible PyTorch with index_url: "https://download.pytorch.org/whl/cu130"';
            $tips[] = '- See https://pytorch.org/get-started/locally/ for the correct install command';
        }

        if (str_contains($output, 'Permission denied') || str_contains($output, 'EPERM')) {
            $tips[] = '- Check directory permissions';
            $tips[] = '- Do NOT use sudo with virtual environments';
        }

        if (str_contains($output, 'conflict') || str_contains($output, 'incompatible')) {
            $tips[] = '- Dependency conflict detected. Try creating a fresh environment';
            $tips[] = '- Use python_env_list with outdated=true to see version mismatches';
        }

        return ToolResult::error(implode("\n", $tips));
    }

    /**
     * Provide a concise summary of installation output.
     */
    private function summarizeInstallOutput(string $output, string $method): string
    {
        $lines = explode("\n", trim($output));

        // Count installed packages from output
        $installed = 0;
        foreach ($lines as $line) {
            if (str_contains($line, 'Successfully installed')) {
                // pip outputs "Successfully installed pkg-1.0 pkg2-2.0 ..."
                $parts = explode(' ', $line);
                $installed = max(0, count($parts) - 2);
                break;
            }
        }

        if ($installed > 0) {
            return "Successfully installed {$installed} package(s) via {$method}.\n\nUse python_env_status to verify the environment.";
        }

        // Return last few meaningful lines if no summary line found
        $meaningful = array_filter($lines, fn(string $l): bool => trim($l) !== '' && !str_starts_with(trim($l), 'Requirement already satisfied'));
        $tail = array_slice($meaningful, -5);

        return implode("\n", $tail) ?: "Installation completed via {$method}.";
    }
}
