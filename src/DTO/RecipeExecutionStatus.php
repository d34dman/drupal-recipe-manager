<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Enum representing the execution status of a recipe.
 */
enum RecipeExecutionStatus: string
{
    /**
     * Recipe has not been executed yet.
     */
    case NOT_EXECUTED = 'not_executed';

    /**
     * Recipe execution was successful.
     */
    case SUCCESS = 'success';

    /**
     * Recipe execution failed.
     */
    case FAILED = 'failed';

    /**
     * Get the display name for the status.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::NOT_EXECUTED => 'Not Executed',
            self::SUCCESS => 'Success',
            self::FAILED => 'Failed',
        };
    }

    /**
     * Check if the status represents a completed execution.
     */
    public function isCompleted(): bool
    {
        return match ($this) {
            self::SUCCESS, self::FAILED => true,
            default => false,
        };
    }

    /**
     * Check if the status represents a successful execution.
     */
    public function isSuccessful(): bool
    {
        return self::SUCCESS === $this;
    }
}
