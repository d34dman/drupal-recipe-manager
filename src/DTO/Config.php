<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Configuration DTO for Drupal Recipe Manager.
 */
class Config
{
    /** @var array<string> */
    private array $scanDirs;

    private ?string $logsDir;

    /** @var array<string, array{command: string}> */
    private array $commands;

    /** @var array<array{input: string, search: string, replace: string, name: string}> */
    private array $variables;

    /**
     * @param array<string>                                                              $scanDirs
     * @param array<string, array{command: string}>                                      $commands
     * @param array<array{input: string, search: string, replace: string, name: string}> $variables
     */
    public function __construct(
        array $scanDirs = [],
        ?string $logsDir = null,
        array $commands = [],
        array $variables = []
    ) {
        $this->scanDirs = $scanDirs;
        $this->logsDir = $logsDir;
        $this->commands = $commands;
        $this->variables = $variables;
    }

    /**
     * Create Config from array.
     *
     * @param array{
     *     scanDirs?: array<string>,
     *     logsDir?: string,
     *     commands?: array<string, array{command: string}>,
     *     variables?: array<array{input: string, search: string, replace: string, name: string}>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['scanDirs'] ?? [],
            $data['logsDir'] ?? null,
            $data['commands'] ?? [],
            $data['variables'] ?? []
        );
    }

    /**
     * Merge with another Config.
     */
    public function merge(self $other): self
    {
        return new self(
            array_unique([...$this->scanDirs, ...$other->scanDirs]),
            $other->logsDir ?? $this->logsDir,
            array_merge($this->commands, $other->commands),
            array_merge($this->variables, $other->variables)
        );
    }

    /**
     * @param array<string> $scanDirs
     */
    public function setScanDirs(array $scanDirs): void
    {
        $this->scanDirs = $scanDirs;
    }

    /**
     * @param array<string, array{command: string}> $commands
     */
    public function setCommands(array $commands): void
    {
        $this->commands = $commands;
    }

    /**
     * @return array<string>
     */
    public function getScanDirs(): array
    {
        return $this->scanDirs;
    }

    public function getLogsDir(): ?string
    {
        return $this->logsDir;
    }

    /**
     * @return array<string, array{command: string}>
     */
    public function getCommands(): array
    {
        return $this->commands;
    }

    /**
     * @param array<array{input: string, search: string, replace: string, name: string}> $variables
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    /**
     * @return array<array{input: string, search: string, replace: string, name: string}>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * @param array{command: string} $command
     */
    public function setCommand(string $name, array $command): void
    {
        $this->commands[$name] = $command;
    }

    /**
     * @param array{input: string, search: string, replace: string, name: string} $variable
     */
    public function addVariable(array $variable): void
    {
        $this->variables[] = $variable;
    }

    /**
     * Convert to array.
     *
     * @return array{
     *     scanDirs: array<string>,
     *     logsDir?: string,
     *     commands: array<string, array{command: string}>,
     *     variables: array<array{input: string, search: string, replace: string, name: string}>
     * }
     */
    public function toArray(): array
    {
        $data = [
            'scanDirs' => $this->scanDirs,
            'commands' => $this->commands,
            'variables' => $this->variables,
        ];

        if (null !== $this->logsDir) {
            $data['logsDir'] = $this->logsDir;
        }

        return $data;
    }

    /**
     * Validate configuration.
     *
     * @throws \InvalidArgumentException if configuration is invalid
     */
    public function validate(): void
    {
        $filesystem = new Filesystem();

        // Validate scan directories
        foreach ($this->scanDirs as $dir) {
            if (!$filesystem->exists($dir)) {
                throw new \InvalidArgumentException("Scan directory does not exist: {$dir}");
            }
        }

        // Validate commands
        foreach ($this->commands as $name => $command) {
            if (!\array_key_exists('command', $command)) {
                throw new \InvalidArgumentException("Command '{$name}' is missing 'command' field");
            }
        }

        // Validate variables
        foreach ($this->variables as $index => $variable) {
            $requiredFields = ['input', 'search', 'replace', 'name'];
            foreach ($requiredFields as $field) {
                if (!\array_key_exists($field, $variable)) {
                    throw new \InvalidArgumentException("Variable at index {$index} is missing '{$field}' field");
                }
            }
        }
    }
}
