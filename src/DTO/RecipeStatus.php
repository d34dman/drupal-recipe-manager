<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Data Transfer Object for Recipe Status.
 */
class RecipeStatus
{
    private bool $executed;

    private ?int $exitCode;

    private ?string $timestamp;

    private ?string $directory;

    private ?string $enabledBy;

    public function __construct(
        bool $executed = false,
        ?int $exitCode = null,
        ?string $timestamp = null,
        ?string $directory = null,
        ?string $enabledBy = null
    ) {
        $this->executed = $executed;
        $this->exitCode = $exitCode;
        $this->timestamp = $timestamp;
        $this->directory = $directory;
        $this->enabledBy = $enabledBy;
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

    public function getEnabledBy(): ?string
    {
        return $this->enabledBy;
    }

    /**
     * @return array{executed: bool, exit_code?: null|int, timestamp?: null|string, directory?: null|string, enabled_by?: null|string}
     */
    public function toArray(): array
    {
        $data = [
            'executed' => $this->executed,
        ];

        if (null !== $this->exitCode) {
            $data['exit_code'] = $this->exitCode;
        }

        if (null !== $this->timestamp) {
            $data['timestamp'] = $this->timestamp;
        }

        if (null !== $this->directory) {
            $data['directory'] = $this->directory;
        }

        if (null !== $this->enabledBy) {
            $data['enabled_by'] = $this->enabledBy;
        }

        return $data;
    }

    /**
     * @param array{executed?: bool, exit_code?: null|int, timestamp?: null|string, directory?: null|string, enabled_by?: null|string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['executed'] ?? false,
            $data['exit_code'] ?? null,
            $data['timestamp'] ?? null,
            $data['directory'] ?? null,
            $data['enabled_by'] ?? null
        );
    }
}
