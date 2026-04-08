<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;

/**
 * Detects Python project configuration, available backends, and existing environments.
 *
 * Call this first for any unfamiliar project — it scans dependency files
 * (requirements.txt, pyproject.toml, environment.yml, etc.), checks for
 * existing venvs, and reports available system tools (python, pip, conda, uv).
 */
final readonly class DetectTool
{
    public function __construct(
        private PythonRunner $runner,
    ) {}

    public function build(): ToolInterface
    {
        return new Tool(
            name: 'python_env_detect',
            description: 'Detect Python project configuration, dependency files, existing virtual environments, and available system tools (python, pip, conda, uv). Call this first for any unfamiliar Python project.',
            parameters: [
                new StringParameter('project_path', 'Absolute path to the Python project directory', required: true),
            ],
            callback: fn(array $args): ToolResult => $this->detect($args['project_path'] ?? ''),
        );
    }

    private function detect(string $projectPath): ToolResult
    {
        if ($projectPath === '' || !is_dir($projectPath)) {
            return ToolResult::error("Directory not found: {$projectPath}");
        }

        $report = [];

        // ── Project dependency files ──
        $depFiles = $this->detectDependencyFiles($projectPath);
        $report[] = '## Dependency Files';
        if ($depFiles === []) {
            $report[] = 'None found — this may not be a Python project, or dependencies are managed externally.';
        } else {
            foreach ($depFiles as $file => $info) {
                $report[] = "- **{$file}**: {$info}";
            }
        }

        // ── Existing environments ──
        $envs = $this->detectExistingEnvironments($projectPath);
        $report[] = '';
        $report[] = '## Existing Environments';
        if ($envs === []) {
            $report[] = 'No virtual environments found in the project directory.';
        } else {
            foreach ($envs as $env) {
                $report[] = "- {$env}";
            }
        }

        // ── Available system tools ──
        $tools = $this->detectSystemTools();
        $report[] = '';
        $report[] = '## System Tools';
        foreach ($tools as $tool => $status) {
            $report[] = "- **{$tool}**: {$status}";
        }

        // ── Recommended next steps ──
        $report[] = '';
        $report[] = '## Recommended Next Steps';
        $steps = $this->recommendNextSteps($depFiles, $envs, $tools);
        foreach ($steps as $i => $step) {
            $report[] = ($i + 1) . ". {$step}";
        }

        return ToolResult::success(implode("\n", $report));
    }

    /**
     * @return array<string, string>
     */
    private function detectDependencyFiles(string $projectPath): array
    {
        $files = [];
        $base = rtrim($projectPath, '/');

        $checks = [
            'requirements.txt' => 'Pip requirements file',
            'pyproject.toml' => 'Modern Python project metadata (PEP 621)',
            'setup.py' => 'Legacy setuptools configuration',
            'setup.cfg' => 'Declarative setuptools configuration',
            'environment.yml' => 'Conda environment file',
            'environment.yaml' => 'Conda environment file',
            'conda.yml' => 'Conda environment file',
            'Pipfile' => 'Pipenv configuration',
            'Pipfile.lock' => 'Pipenv lock file',
            'uv.lock' => 'uv lock file',
            'poetry.lock' => 'Poetry lock file',
            '.python-version' => 'Python version pin file',
        ];

        foreach ($checks as $filename => $description) {
            $path = $base . '/' . $filename;
            if (is_file($path)) {
                $size = filesize($path);
                $files[$filename] = "{$description} (" . $this->formatBytes($size !== false ? $size : 0) . ')';
            }
        }

        // Check for extra requirements files (requirements-dev.txt, requirements-cuda.txt, etc.)
        $extraReqs = glob($base . '/requirements*.txt') ?: [];
        foreach ($extraReqs as $reqFile) {
            $name = basename($reqFile);
            if ($name !== 'requirements.txt' && !isset($files[$name])) {
                $size = filesize($reqFile);
                $files[$name] = "Additional pip requirements (" . $this->formatBytes($size !== false ? $size : 0) . ')';
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function detectExistingEnvironments(string $projectPath): array
    {
        $envs = [];
        $base = rtrim($projectPath, '/');

        $candidates = ['.venv', 'venv', 'env', '.env'];

        foreach ($candidates as $envName) {
            $envPath = $base . '/' . $envName;
            $python = $this->runner->resolveVenvPython($projectPath, $envName);
            if ($python !== '') {
                // Get Python version
                $result = $this->runner->runPythonCode($projectPath, 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}.{sys.version_info.micro}")', $envName, 5);
                $version = $result->succeeded() ? trim($result->stdout) : 'unknown version';
                $envs[] = "**{$envName}/** — Python {$version} at {$envPath}";
            } elseif (is_dir($envPath)) {
                $envs[] = "**{$envName}/** — directory exists but no valid Python interpreter found (possibly broken)";
            }
        }

        return $envs;
    }

    /**
     * @return array<string, string>
     */
    private function detectSystemTools(): array
    {
        $tools = [];

        // Python
        $python = $this->runner->resolveSystemPython();
        if ($python !== '') {
            $version = trim((string) shell_exec(escapeshellarg($python) . ' --version 2>&1'));
            $tools['python'] = "{$version} ({$python})";
        } else {
            $tools['python'] = 'NOT FOUND — install Python 3.9+ from https://python.org';
        }

        // pip
        if ($python !== '') {
            $pipVersion = trim((string) shell_exec(escapeshellarg($python) . ' -m pip --version 2>&1'));
            $tools['pip'] = $pipVersion !== '' ? $pipVersion : 'not available';
        } else {
            $tools['pip'] = 'requires Python';
        }

        // venv module
        if ($python !== '') {
            $venvCheck = trim((string) shell_exec(escapeshellarg($python) . ' -c "import venv; print(\'available\')" 2>&1'));
            $tools['venv'] = str_contains($venvCheck, 'available') ? 'available' : 'NOT AVAILABLE — install python3-venv package';
        }

        // conda
        $conda = $this->runner->resolveConda();
        if ($conda !== '') {
            $condaVersion = trim((string) shell_exec(escapeshellarg($conda) . ' --version 2>&1'));
            $tools['conda'] = "{$condaVersion} ({$conda})";
        } else {
            $tools['conda'] = 'not installed (optional)';
        }

        // uv
        $uv = $this->runner->resolveUv();
        if ($uv !== '') {
            $uvVersion = trim((string) shell_exec(escapeshellarg($uv) . ' --version 2>&1'));
            $tools['uv'] = "{$uvVersion} ({$uv})";
        } else {
            $tools['uv'] = 'not installed (optional — fast alternative to pip)';
        }

        return $tools;
    }

    /**
     * @param array<string, string> $depFiles
     * @param list<string> $envs
     * @param array<string, string> $tools
     * @return list<string>
     */
    private function recommendNextSteps(array $depFiles, array $envs, array $tools): array
    {
        $steps = [];

        // No environment yet
        if ($envs === []) {
            $backend = 'venv';
            if (isset($depFiles['environment.yml']) || isset($depFiles['environment.yaml']) || isset($depFiles['conda.yml'])) {
                $backend = 'conda';
            } elseif (isset($depFiles['uv.lock'])) {
                $backend = 'uv';
            } elseif (str_contains($tools['uv'] ?? '', '/')) {
                $backend = 'uv'; // Prefer uv if installed (faster)
            }
            $steps[] = "Create a virtual environment with `python_env_create` using the **{$backend}** backend.";
        }

        // Has dependency files to install
        if (isset($depFiles['pyproject.toml'])) {
            $steps[] = 'Install the project with `python_env_install` using `extras` parameter (e.g., `.[dev]` for development mode).';
        } elseif (isset($depFiles['requirements.txt'])) {
            $steps[] = 'Install dependencies with `python_env_install` using `requirements_file: "requirements.txt"`.';
        } elseif (isset($depFiles['environment.yml']) || isset($depFiles['environment.yaml'])) {
            $steps[] = 'Create the conda environment from the environment.yml file.';
        }

        // Environment exists — check status
        if ($envs !== []) {
            $steps[] = 'Run `python_env_status` to check the current environment health and installed packages.';
        }

        if ($steps === []) {
            $steps[] = 'This directory has no Python project markers. Create a new project or navigate to the correct directory.';
        }

        return $steps;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1_048_576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1_048_576, 1) . ' MB';
    }
}
