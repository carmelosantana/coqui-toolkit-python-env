<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Runtime;

/**
 * Subprocess execution engine for Python, pip, conda, uv, and arbitrary shell commands.
 *
 * All methods accept a $projectPath so the agent can manage multiple Python projects
 * in a single session. Virtual environments are resolved relative to that project path.
 */
final class PythonRunner
{
    public const int DEFAULT_TIMEOUT = 30;
    public const int INSTALL_TIMEOUT = 300;
    public const int MAX_OUTPUT_BYTES = 131_072; // 128 KB

    /** Cached binary paths to avoid repeated `which` lookups. */
    private string $cachedSystemPython = '';
    private string $cachedConda = '';
    private string $cachedUv = '';

    // ──────────────────────────────────────────────────────────────────────
    //  Binary resolution
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Find the system-level python3 or python binary.
     */
    public function resolveSystemPython(): string
    {
        if ($this->cachedSystemPython !== '') {
            return $this->cachedSystemPython;
        }

        foreach (['python3', 'python'] as $candidate) {
            $path = trim((string) shell_exec("which {$candidate} 2>/dev/null"));
            if ($path !== '' && is_executable($path)) {
                $this->cachedSystemPython = $path;
                return $path;
            }
        }

        return '';
    }

    /**
     * Find the Python binary inside a project's virtual environment.
     */
    public function resolveVenvPython(string $projectPath, string $envName = '.venv'): string
    {
        $base = rtrim($projectPath, '/') . '/' . $envName;
        $candidates = [
            $base . '/bin/python',
            $base . '/bin/python3',
            $base . '/Scripts/python.exe',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Find the conda binary on the system.
     */
    public function resolveConda(): string
    {
        if ($this->cachedConda !== '') {
            return $this->cachedConda;
        }

        // Try `which` first
        $path = trim((string) shell_exec('which conda 2>/dev/null'));
        if ($path !== '' && is_executable($path)) {
            $this->cachedConda = $path;
            return $path;
        }

        // Common install locations
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        $commonPaths = [
            $home . '/miniconda3/bin/conda',
            $home . '/anaconda3/bin/conda',
            $home . '/miniforge3/bin/conda',
            '/opt/conda/bin/conda',
            '/usr/local/bin/conda',
        ];

        foreach ($commonPaths as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $this->cachedConda = $candidate;
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Find the uv binary on the system.
     */
    public function resolveUv(): string
    {
        if ($this->cachedUv !== '') {
            return $this->cachedUv;
        }

        $path = trim((string) shell_exec('which uv 2>/dev/null'));
        if ($path !== '' && is_executable($path)) {
            $this->cachedUv = $path;
            return $path;
        }

        // Common install locations for uv
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        $commonPaths = [
            $home . '/.local/bin/uv',
            $home . '/.cargo/bin/uv',
            '/usr/local/bin/uv',
        ];

        foreach ($commonPaths as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $this->cachedUv = $candidate;
                return $candidate;
            }
        }

        return '';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Execution methods
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Run `python -m pip <args>` inside a project's venv.
     *
     * @param list<string> $args
     */
    public function runPip(
        string $projectPath,
        array $args,
        string $envName = '.venv',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $python = $this->resolveVenvPython($projectPath, $envName);
        if ($python === '') {
            return $this->venvNotFoundResult($projectPath, $envName);
        }

        $command = escapeshellarg($python) . ' -m pip ' . implode(' ', array_map(escapeshellarg(...), $args));

        return $this->execute($command, $projectPath, $this->buildEnv($projectPath, $envName), $timeout);
    }

    /**
     * Run inline Python code via `python -c "<code>"` inside a venv.
     */
    public function runPythonCode(
        string $projectPath,
        string $code,
        string $envName = '.venv',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $python = $this->resolveVenvPython($projectPath, $envName);
        if ($python === '') {
            return $this->venvNotFoundResult($projectPath, $envName);
        }

        $command = escapeshellarg($python) . ' -c ' . escapeshellarg($code);

        return $this->execute($command, $projectPath, $this->buildEnv($projectPath, $envName), $timeout);
    }

    /**
     * Run a Python script file inside a venv.
     *
     * @param list<string> $args
     */
    public function runScript(
        string $projectPath,
        string $script,
        array $args = [],
        string $envName = '.venv',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $python = $this->resolveVenvPython($projectPath, $envName);
        if ($python === '') {
            return $this->venvNotFoundResult($projectPath, $envName);
        }

        $command = escapeshellarg($python) . ' ' . escapeshellarg($script);
        if ($args !== []) {
            $command .= ' ' . implode(' ', array_map(escapeshellarg(...), $args));
        }

        return $this->execute($command, $projectPath, $this->buildEnv($projectPath, $envName), $timeout);
    }

    /**
     * Run a Python module via `python -m <module>` inside a venv.
     *
     * @param list<string> $args
     */
    public function runModule(
        string $projectPath,
        string $module,
        array $args = [],
        string $envName = '.venv',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $python = $this->resolveVenvPython($projectPath, $envName);
        if ($python === '') {
            return $this->venvNotFoundResult($projectPath, $envName);
        }

        $command = escapeshellarg($python) . ' -m ' . escapeshellarg($module);
        if ($args !== []) {
            $command .= ' ' . implode(' ', array_map(escapeshellarg(...), $args));
        }

        return $this->execute($command, $projectPath, $this->buildEnv($projectPath, $envName), $timeout);
    }

    /**
     * Run a conda command.
     *
     * @param list<string> $args
     */
    public function runConda(
        array $args,
        ?string $cwd = null,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $conda = $this->resolveConda();
        if ($conda === '') {
            return new PythonResult(1, '', 'conda not found. Install Miniconda or Anaconda first: https://docs.conda.io/en/latest/miniconda.html');
        }

        $command = escapeshellarg($conda) . ' ' . implode(' ', array_map(escapeshellarg(...), $args));

        return $this->execute($command, $cwd ?? getcwd() ?: '/tmp', null, $timeout);
    }

    /**
     * Run a uv command.
     *
     * @param list<string> $args
     */
    public function runUv(
        string $projectPath,
        array $args,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $uv = $this->resolveUv();
        if ($uv === '') {
            return new PythonResult(1, '', 'uv not found. Install with: curl -LsSf https://astral.sh/uv/install.sh | sh');
        }

        $command = escapeshellarg($uv) . ' ' . implode(' ', array_map(escapeshellarg(...), $args));

        return $this->execute($command, $projectPath, null, $timeout);
    }

    /**
     * Run an arbitrary shell command in the project directory with optional venv context.
     */
    public function runShell(
        string $projectPath,
        string $command,
        ?string $envName = null,
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $env = $envName !== null ? $this->buildEnv($projectPath, $envName) : null;

        return $this->execute($command, $projectPath, $env, $timeout);
    }

    /**
     * Create a virtual environment using the system Python's venv module.
     */
    public function createVenv(
        string $projectPath,
        string $envName = '.venv',
        int $timeout = self::DEFAULT_TIMEOUT,
    ): PythonResult {
        $python = $this->resolveSystemPython();
        if ($python === '') {
            return new PythonResult(1, '', 'No system Python found. Install Python 3.9+ first.');
        }

        $envPath = rtrim($projectPath, '/') . '/' . $envName;
        $command = escapeshellarg($python) . ' -m venv ' . escapeshellarg($envPath);

        return $this->execute($command, $projectPath, null, $timeout);
    }

    /**
     * Check whether a virtual environment exists at the given project path.
     */
    public function venvExists(string $projectPath, string $envName = '.venv'): bool
    {
        return $this->resolveVenvPython($projectPath, $envName) !== '';
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Environment building
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build the environment variables for running inside a virtual environment.
     *
     * This "activates" the venv for subprocesses by manipulating PATH and VIRTUAL_ENV
     * — no shell sourcing of activate scripts needed.
     *
     * @return array<string, string>
     */
    public function buildEnv(string $projectPath, string $envName = '.venv'): array
    {
        $env = [];

        // Inherit current environment
        /** @var array<string, string> $envVars */
        $envVars = getenv();
        foreach ($envVars as $key => $value) {
            $env[$key] = $value;
        }

        $envPath = rtrim($projectPath, '/') . '/' . $envName;
        $binDir = $envPath . '/bin';

        if (is_dir($envPath . '/Scripts')) {
            $binDir = $envPath . '/Scripts'; // Windows
        }

        // Prepend venv bin to PATH
        $currentPath = $env['PATH'] ?? (getenv('PATH') ?: '/usr/bin:/bin');
        $env['PATH'] = $binDir . ':' . $currentPath;

        // Set VIRTUAL_ENV and remove PYTHONHOME (breaks venv activation)
        $env['VIRTUAL_ENV'] = $envPath;
        unset($env['PYTHONHOME']);

        // Add src/ to PYTHONPATH if it exists (common source layout like Jasper)
        $srcDir = rtrim($projectPath, '/') . '/src';
        if (is_dir($srcDir)) {
            $pythonPath = $env['PYTHONPATH'] ?? '';
            $env['PYTHONPATH'] = $pythonPath !== '' ? ($srcDir . ':' . $pythonPath) : $srcDir;
        }

        return $env;
    }

    // ──────────────────────────────────────────────────────────────────────
    //  Private execution core
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Execute a command via proc_open with timeout support and output truncation.
     *
     * @param array<string, string>|null $env
     */
    private function execute(
        string $command,
        string $cwd,
        ?array $env,
        int $timeout,
    ): PythonResult {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return new PythonResult(1, '', "Failed to start process: {$command}");
        }

        // Close stdin immediately — we don't send input
        fclose($pipes[0]);

        // Set non-blocking on stdout/stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        while (true) {
            $status = proc_get_status($process);

            // Read available data
            $chunk = (string) stream_get_contents($pipes[1]);
            if ($chunk !== '') {
                $stdout .= $chunk;
            }

            $chunk = (string) stream_get_contents($pipes[2]);
            if ($chunk !== '') {
                $stderr .= $chunk;
            }

            // Process exited
            if (!$status['running']) {
                break;
            }

            // Timeout check
            if ($timeout > 0 && (time() - $startTime) >= $timeout) {
                // Graceful termination first
                proc_terminate($process, 15); // SIGTERM
                usleep(500_000); // 500ms grace

                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }

                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return new PythonResult(
                    124, // timeout exit code, like GNU timeout
                    $this->truncateOutput($stdout),
                    $this->truncateOutput($stderr) . "\n[Process timed out after {$timeout}s]",
                );
            }

            usleep(50_000); // 50ms poll interval
        }

        // Final read to catch any remaining buffered output
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $status['exitcode'];
        if ($exitCode === -1) {
            $exitCode = proc_close($process);
        } else {
            proc_close($process);
        }

        return new PythonResult(
            $exitCode,
            $this->truncateOutput($stdout),
            $this->truncateOutput($stderr),
        );
    }

    /**
     * Truncate output if it exceeds the maximum byte limit.
     */
    private function truncateOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n[Output truncated at " . self::MAX_OUTPUT_BYTES . " bytes]";
    }

    /**
     * Standard error result when a virtual environment is not found.
     */
    private function venvNotFoundResult(string $projectPath, string $envName): PythonResult
    {
        return new PythonResult(
            1,
            '',
            "Virtual environment not found at {$projectPath}/{$envName}. "
            . "Create one first with python_env_create.",
        );
    }
}
