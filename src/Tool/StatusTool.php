<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

/**
 * Reports the health and status of a Python virtual environment.
 *
 * Shows Python version, pip version, installed package count, key packages
 * (numpy, torch, etc.), CUDA availability, and disk usage.
 */
final readonly class StatusTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_status',
            description: 'Show the status of a Python virtual environment — Python version, pip version, installed packages, CUDA support, and disk usage.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
                new StringParameter('env_name', 'Name of the environment directory (default: .venv)'),
            ],
            callback: fn(array $args): ToolResult => $this->status(
                projectPath: $args['project_path'] ?? '',
                envName: $args['env_name'] ?? '.venv',
            ),
        );
    }

    private function status(string $projectPath, string $envName): ToolResult
    {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        if (!$this->runner->venvExists($projectPath, $envName)) {
            return ToolResult::error(
                "No virtual environment found at {$projectPath}/{$envName}. "
                . 'Create one with python_env_create.',
            );
        }

        $report = ["## Environment Status: {$projectPath}/{$envName}"];
        $report[] = '';

        // Python version
        $pythonResult = $this->runner->runPythonCode(
            $projectPath,
            'import sys; print(f"Python {sys.version}")',
            $envName,
            5,
        );
        $report[] = '**Python:** ' . ($pythonResult->succeeded() ? trim($pythonResult->stdout) : 'unknown');

        // pip version
        $pipResult = $this->runner->runPip($projectPath, ['--version'], $envName, 5);
        if ($pipResult->succeeded()) {
            $report[] = '**pip:** ' . trim($pipResult->stdout);
        }

        // Package count
        $countResult = $this->runner->runPip($projectPath, ['list', '--format=json'], $envName, 10);
        if ($countResult->succeeded()) {
            $packages = $countResult->json();
            if ($packages !== null) {
                $report[] = '**Installed packages:** ' . count($packages);
            }
        }

        // Key packages check
        $report[] = '';
        $report[] = '### Key Packages';
        $keyPackages = [
            'numpy', 'pandas', 'scipy', 'matplotlib', 'scikit-learn',
            'torch', 'tensorflow', 'transformers', 'flask', 'django',
            'fastapi', 'requests', 'pytest', 'black', 'ruff',
        ];

        $checkCode = <<<'PYTHON'
import importlib, json
packages = %s
result = {}
for pkg in packages:
    try:
        mod = importlib.import_module(pkg.replace('-', '_'))
        ver = getattr(mod, '__version__', 'installed')
        result[pkg] = ver
    except ImportError:
        pass
print(json.dumps(result))
PYTHON;

        $checkCode = sprintf($checkCode, json_encode($keyPackages));
        $keyResult = $this->runner->runPythonCode($projectPath, $checkCode, $envName, 10);
        if ($keyResult->succeeded()) {
            $found = json_decode(trim($keyResult->stdout), true);
            if (is_array($found) && $found !== []) {
                foreach ($found as $pkg => $version) {
                    $report[] = "- **{$pkg}**: {$version}";
                }
            } else {
                $report[] = 'No commonly-known packages detected.';
            }
        }

        // CUDA check
        $report[] = '';
        $report[] = '### GPU / CUDA';
        $cudaCode = <<<'PYTHON'
try:
    import torch
    print(f"PyTorch {torch.__version__}")
    print(f"CUDA available: {torch.cuda.is_available()}")
    if torch.cuda.is_available():
        print(f"CUDA version: {torch.version.cuda}")
        print(f"GPU: {torch.cuda.get_device_name(0)}")
        print(f"GPU memory: {torch.cuda.get_device_properties(0).total_mem / 1e9:.1f} GB")
except ImportError:
    print("PyTorch not installed — CUDA check skipped")
PYTHON;
        $cudaResult = $this->runner->runPythonCode($projectPath, $cudaCode, $envName, 10);
        $report[] = $cudaResult->succeeded() ? trim($cudaResult->stdout) : 'Could not check CUDA status.';

        // Disk usage
        $report[] = '';
        $envPath = rtrim($projectPath, '/') . '/' . $envName;
        $duResult = $this->runner->runShell($projectPath, 'du -sh ' . escapeshellarg($envPath) . ' 2>/dev/null');
        if ($duResult->succeeded() && trim($duResult->stdout) !== '') {
            $parts = explode("\t", $duResult->stdout);
            $report[] = '**Disk usage:** ' . trim($parts[0]);
        }

        return ToolResult::success(implode("\n", $report));
    }
}
