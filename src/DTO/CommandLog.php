<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Data Transfer Object for Command Log.
 */
class CommandLog
{
    private string $timestamp;

    private string $recipe;

    private string $commandName;

    private string $actualCommand;

    private int $exitCode;

    private string $status;

    public function __construct(
        string $timestamp,
        string $recipe,
        string $commandName,
        string $actualCommand,
        int $exitCode,
        string $status
    ) {
        $this->timestamp = $timestamp;
        $this->recipe = $recipe;
        $this->commandName = $commandName;
        $this->actualCommand = $actualCommand;
        $this->exitCode = $exitCode;
        $this->status = $status;
    }

    public function getTimestamp(): string
    {
        return $this->timestamp;
    }

    public function getRecipe(): string
    {
        return $this->recipe;
    }

    public function getCommandName(): string
    {
        return $this->commandName;
    }

    public function getActualCommand(): string
    {
        return $this->actualCommand;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return array{
     *     timestamp: string,
     *     recipe: string,
     *     command: string,
     *     actual_command: string,
     *     exit_code: int,
     *     status: string
     * }
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'recipe' => $this->recipe,
            'command' => $this->commandName,
            'actual_command' => $this->actualCommand,
            'exit_code' => $this->exitCode,
            'status' => $this->status,
        ];
    }

    /**
     * @param array{
     *     timestamp: string,
     *     recipe: string,
     *     command: string,
     *     actual_command: string,
     *     exit_code: int,
     *     status: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['timestamp'],
            $data['recipe'],
            $data['command'],
            $data['actual_command'],
            $data['exit_code'],
            $data['status']
        );
    }
}
