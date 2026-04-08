# Coqui Toolkit: Python ENV

Manage Python virtual environments and dependencies through Coqui. Supports **pip** (venv), **conda**, and **uv** backends.

## Installation

```bash
composer require coquibot/coqui-toolkit-python-env
```

Coqui discovers the toolkit automatically via `composer.json` metadata.

## Requirements

- PHP 8.4+
- Python 3.8+ (system-installed)
- One or more Python environment backends:
  - **venv + pip** — included with Python (default)
  - **conda** — [Miniconda](https://docs.conda.io/en/latest/miniconda.html) or Anaconda
  - **uv** — [Astral's uv](https://github.com/astral-sh/uv) (fast alternative)

## Tools

| Tool | Description |
|------|-------------|
| `python_env_detect` | Scan a project directory for dependency files, existing environments, and system tools |
| `python_env_create` | Create a new virtual environment (venv, conda, or uv) |
| `python_env_status` | Check environment health: Python version, pip version, package count, CUDA support |
| `python_env_install` | Install packages from names, requirements.txt, pyproject.toml extras, or conda env files |
| `python_env_list` | List installed packages in table, freeze, or JSON format |
| `python_env_run` | Execute inline Python code, scripts, or modules inside the environment |
| `python_env_remove` | Delete an environment with safety confirmations |

## Typical Workflow

```
1. python_env_detect  → Scan the project, find dependency files
2. python_env_create  → Create a .venv in the project directory
3. python_env_install → Install dependencies from requirements.txt or pyproject.toml
4. python_env_status  → Verify everything is working
5. python_env_run     → Execute code or scripts
```

## Backend Comparison

| Feature | venv + pip | conda | uv |
|---------|-----------|-------|-----|
| Speed | Moderate | Slow | Very fast |
| Python version management | No | Yes | Yes |
| Non-Python packages (C libs) | No | Yes | No |
| GPU/CUDA packages | Via index URL | Native | Via index URL |
| Disk usage | Low | High | Low |
| Best for | General projects | Scientific/ML | Modern Python |

## Examples

### Standard Python Project (requirements.txt)

```
python_env_detect(project_path: "/path/to/project")
python_env_create(project_path: "/path/to/project")
python_env_install(project_path: "/path/to/project", requirements_file: "requirements.txt")
```

### Project with pyproject.toml and Extras

```
python_env_detect(project_path: "/path/to/jasper")
python_env_create(project_path: "/path/to/jasper")
python_env_install(project_path: "/path/to/jasper", requirements_file: "pyproject.toml")
python_env_install(project_path: "/path/to/jasper", requirements_file: "pyproject.toml[dev,test]")
```

### GPU/CUDA Project (PyTorch)

```
python_env_create(project_path: "/path/to/project")
python_env_install(
    project_path: "/path/to/project",
    packages: ["torch", "torchvision"],
    index_url: "https://download.pytorch.org/whl/cu121"
)
python_env_install(project_path: "/path/to/project", requirements_file: "requirements.txt")
```

### Conda Environment from environment.yml

```
python_env_create(project_path: "/path/to/project", backend: "conda")
python_env_install(project_path: "/path/to/project", requirements_file: "environment.yml", backend: "conda")
```

### Using uv for Fast Installs

```
python_env_create(project_path: "/path/to/project", backend: "uv")
python_env_install(project_path: "/path/to/project", requirements_file: "requirements.txt", backend: "uv")
```

## Parameters Reference

### python_env_detect

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `project_path` | string | Yes | Absolute path to the project directory |

### python_env_create

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `backend` | enum | No | `venv` | `venv`, `conda`, or `uv` |
| `python_version` | string | No | — | Python version for conda/uv (e.g. `3.11`) |
| `env_name` | string | No | `.venv` | Environment directory name |

### python_env_status

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `env_name` | string | No | `.venv` | Environment directory name |

### python_env_install

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `packages` | array | No | — | List of package specifiers (e.g. `["numpy>=1.26", "pandas"]`) |
| `requirements_file` | string | No | — | Path to requirements.txt, pyproject.toml, or environment.yml |
| `backend` | enum | No | `pip` | `pip`, `conda`, or `uv` |
| `env_name` | string | No | `.venv` | Environment directory name |
| `index_url` | string | No | — | Custom package index (e.g. PyTorch CUDA wheels) |

### python_env_list

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `format` | enum | No | `table` | Output format: `table`, `freeze`, or `json` |
| `outdated` | bool | No | `false` | Show only packages with available updates |
| `filter` | string | No | — | Filter packages by name substring |
| `env_name` | string | No | `.venv` | Environment directory name |

### python_env_run

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `code` | string | No | — | Inline Python code to execute |
| `script` | string | No | — | Path to a .py script (relative to project) |
| `module` | string | No | — | Module to run (e.g. `pytest`, `http.server`) |
| `args` | string | No | — | Additional CLI arguments |
| `timeout` | number | No | `30` | Execution timeout in seconds |
| `env_name` | string | No | `.venv` | Environment directory name |

### python_env_remove

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `project_path` | string | Yes | — | Absolute path to the project directory |
| `env_name` | string | No | `.venv` | Environment directory name |
| `confirm` | bool | Yes | — | Must be `true` to proceed |

## Development

```bash
composer install
composer test
./vendor/bin/phpstan analyse
```

## License

MIT
