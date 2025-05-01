<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Data Transfer Object for Recipe Status.
 */
class RecipeStatus
{
    private RecipeExecutionStatus $status;

    private ?int $exitCode;

    private ?string $timestamp;

    private ?string $directory;

    private ?string $enabledBy;

    public function __construct(
        RecipeExecutionStatus $status = RecipeExecutionStatus::NOT_EXECUTED,
        ?int $exitCode = null,
        ?string $timestamp = null,
        ?string $directory = null,
        ?string $enabledBy = null
    ) {
        $this->status = $status;
        $this->exitCode = $exitCode;
        $this->timestamp = $timestamp;
        $this->directory = $directory;
        $this->enabledBy = $enabledBy;
    }

    public function getStatus(): RecipeExecutionStatus
    {
        return $this->status;
    }

    public function isExecuted(): bool
    {
        return $this->status->isCompleted();
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
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

    public function getEnabledBy(): ?string
    {
        return $this->enabledBy;
    }

    /**
     * @return array{status: string, exit_code?: null|int, timestamp?: null|string, directory?: null|string, enabled_by?: null|string}
     */
    public function toArray(): array
    {
        $data = [
            "status" => $this->status->value,
        ];

        if (null !== $this->exitCode) {
            $data["exit_code"] = $this->exitCode;
        }

        if (null !== $this->timestamp) {
            $data["timestamp"] = $this->timestamp;
        }

        if (null !== $this->directory) {
            $data["directory"] = $this->directory;
        }

        if (null !== $this->enabledBy) {
            $data["enabled_by"] = $this->enabledBy;
        }

        return $data;
    }

    /**
     * @param array{status?: string, exit_code?: null|int, timestamp?: null|string, directory?: null|string, enabled_by?: null|string} $data
     */
    public static function fromArray(array $data): self
    {
        $status = isset($data["status"]) 
            ? RecipeExecutionStatus::from($data["status"]) 
            : RecipeExecutionStatus::NOT_EXECUTED;

        return new self(
            $status,
            $data["exit_code"] ?? null,
            $data["timestamp"] ?? null,
            $data["directory"] ?? null,
            $data["enabled_by"] ?? null
        );
    }
}
