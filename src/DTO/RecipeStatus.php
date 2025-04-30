<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Data Transfer Object for Recipe Status
 */
class RecipeStatus
{
    private bool $executed;
    private ?int $exitCode;
    private ?string $timestamp;
    private ?string $directory;

    public function __construct(
        bool $executed = false,
        ?int $exitCode = null,
        ?string $timestamp = null,
        ?string $directory = null
    ) {
        $this->executed = $executed;
        $this->exitCode = $exitCode;
        $this->timestamp = $timestamp;
        $this->directory = $directory;
    }

    public function isExecuted(): bool
    {
        return $this->executed;
    }

    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    public function getTimestamp(): ?string
    {
        return $this->timestamp;
    }

    public function getDirectory(): ?string
    {
        return $this->directory;
    }

    /**
     * @return array{executed: bool, exit_code?: int|null, timestamp?: string|null, directory?: string|null}
     */
    public function toArray(): array
    {
        $data = [
            "executed" => $this->executed,
        ];

        if ($this->exitCode !== null) {
            $data["exit_code"] = $this->exitCode;
        }

        if ($this->timestamp !== null) {
            $data["timestamp"] = $this->timestamp;
        }

        if ($this->directory !== null) {
            $data["directory"] = $this->directory;
        }

        return $data;
    }

    /**
     * @param array{executed?: bool, exit_code?: int|null, timestamp?: string|null, directory?: string|null} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data["executed"] ?? false,
            $data["exit_code"] ?? null,
            $data["timestamp"] ?? null,
            $data["directory"] ?? null
        );
    }
} 