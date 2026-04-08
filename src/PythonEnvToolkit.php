<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Toolkits\PythonEnv\Runtime\PythonRunner;
use CoquiBot\Toolkits\PythonEnv\Tool\CreateTool;
use CoquiBot\Toolkits\PythonEnv\Tool\DetectTool;
use CoquiBot\Toolkits\PythonEnv\Tool\InstallTool;
use CoquiBot\Toolkits\PythonEnv\Tool\ListTool;
use CoquiBot\Toolkits\PythonEnv\Tool\RemoveTool;
use CoquiBot\Toolkits\PythonEnv\Tool\RunTool;
use CoquiBot\Toolkits\PythonEnv\Tool\StatusTool;

/**
 * Python virtual environment and dependency management toolkit.
 *
 * Provides 7 tools for creating, inspecting, installing into, listing,
 * running commands in, and removing Python virtual environments.
 * Supports three backends: venv+pip (standard), conda, and uv.
 *
 * Each tool accepts a project_path parameter, allowing the agent to manage
 * multiple Python projects in a single session.
 */
final class PythonEnvToolkit implements ToolkitInterface
{
    private PythonRunner $runner;

    public function __construct()
    {
        $this->runner = new PythonRunner();
    }

    /**
     * @return list<\CarmeloSantana\PHPAgents\Contract\ToolInterface>
     */
    #[\Override]
    public function tools(): array
    {
        return [
            (new DetectTool($this->runner))->build(),
            (new CreateTool($this->runner))->build(),
            (new StatusTool($this->runner))->build(),
            (new InstallTool($this->runner))->build(),
            (new ListTool($this->runner))->build(),
            (new RunTool($this->runner))->build(),
            (new RemoveTool($this->runner))->build(),
        ];
    }

    #[\Override]
    public function guidelines(): string
    {
        return <<<'GUIDELINES'
        <python-env-toolkit>
        # Python Environment Management

        You have 7 tools for managing Python virtual environments and dependencies across any Python project.

        ## Workflow

        When working with an unfamiliar Python project, **always start with `python_env_detect`**. It scans the project directory for dependency files, existing environments, and available system tools, then recommends the correct setup steps.

        **Typical setup flow:**
        1. `python_env_detect` — scan the project to understand its configuration
        2. `python_env_create` — create a virtual environment (if none exists)
        3. `python_env_install` — install dependencies from requirements.txt, pyproject.toml, or individual packages
        4. `python_env_status` — verify the environment is healthy and key packages are installed

        ## Backend Selection

        | Backend | Best For | Speed | Notes |
        |---------|----------|-------|-------|
        | **venv** | Most projects | Standard | Uses Python's built-in venv module + pip. Most compatible. Default choice. |
        | **conda** | Scientific computing, specific Python versions | Slow | Better at resolving complex dependency trees (numpy, scipy, CUDA). Use when environment.yml exists or user prefers conda. |
        | **uv** | Fast installs, modern projects | Fastest | 10-100x faster than pip. Drop-in replacement. Use when uv is available on the system. |

        ## Key Patterns

        ### Activation
        Virtual environments are "activated" automatically for every tool call by setting PATH and VIRTUAL_ENV environment variables in the subprocess. You do NOT need to source activate scripts — all tools handle this transparently.

        ### Per-Project Path
        Every tool accepts a `project_path` parameter. You can manage multiple Python projects in one session by passing different paths.

        ### CUDA / GPU PyTorch
        For GPU-accelerated PyTorch, use the `index_url` parameter in `python_env_install`:
        - NVIDIA CUDA 13.0: `https://download.pytorch.org/whl/cu130`
        - NVIDIA CUDA 12.6: `https://download.pytorch.org/whl/cu126`
        - AMD ROCm 7.1: `https://download.pytorch.org/whl/rocm7.1`
        Install PyTorch BEFORE other packages (like ComfyUI's requirements.txt) to avoid version conflicts.

        ### pyproject.toml Projects (e.g., Jasper)
        Projects using pyproject.toml with optional dependencies should use the `extras` parameter:
        - Development: `extras: "dev"`
        - CUDA support: `extras: "cuda"`
        - Multiple extras: `extras: "dev,cuda"`
        This runs `pip install -e ".[dev,cuda]"` which installs the project in editable mode with the specified extras.

        ### Complex Projects (e.g., ComfyUI)
        Large projects with GPU dependencies need careful setup order:
        1. Create environment
        2. Install GPU framework first (PyTorch with correct CUDA index URL)
        3. Then install project requirements.txt
        4. Check status to verify CUDA is available

        ## Troubleshooting
        - **"No module named venv"** — On Debian/Ubuntu: `sudo apt install python3-venv`
        - **Dependency conflicts** — Remove environment and recreate with a fresh install
        - **Wrong Python version** — Use conda or uv backend with `python_version` to specify the exact version
        - **Slow installs** — Switch to uv backend if available (10-100x faster than pip)
        - **CUDA not detected** — Install PyTorch with the correct CUDA index URL for your GPU
        </python-env-toolkit>
        GUIDELINES;
    }
}
