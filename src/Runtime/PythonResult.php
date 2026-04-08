<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\PythonEnv\Runtime;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Immutable result of a subprocess execution (python, pip, conda, uv, shell).
 */
final readonly class PythonResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function output(): string
    {
        $parts = [];
        if ($this->stdout !== '') {
            $parts[] = $this->stdout;
        }
        if ($this->stderr !== '') {
            $parts[] = $this->stderr;
        }

        return implode("\n", $parts);
    }

    public function toToolResult(): ToolResult
    {
        $output = $this->output();
        if ($output === '') {
            $output = $this->succeeded() ? 'Command completed successfully.' : 'Command failed with no output.';
        }

        return $this->succeeded()
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    /**
     * Try to decode stdout as a JSON array/object.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        if ($this->stdout === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->stdout, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }
}
