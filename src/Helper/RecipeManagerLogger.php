<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Helper;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use D34dman\DrupalRecipeManager\DTO\RecipeStatus;
use D34dman\DrupalRecipeManager\DTO\CommandLog;
use D34dman\DrupalRecipeManager\DTO\Config;

/**
 * Helper class for logging recipe execution
 */
class RecipeManagerLogger
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Log recipe execution to history and update status
     *
     * @param string|null $recipe The recipe name
     * @param string|null $commandName The command name
     * @param string|null $command The actual command executed
     * @param int|null $exitCode The command exit code
     * @param string|null $recipePath The path to the recipe
     */
    public function logExecution(?string $recipe, ?string $commandName, ?string $command, ?int $exitCode, ?string $recipePath): void
    {
        if ($recipe === null || $commandName === null || $command === null || $exitCode === null || $recipePath === null) {
            return;
        }

        $this->logToHistory($recipe, $commandName, $command, $exitCode);
        $this->updateRecipeStatus($recipe, $exitCode, $commandName, $recipePath);
    }

    /**
     * Log execution to command history
     */
    private function logToHistory(?string $recipe, ?string $commandName, ?string $command, ?int $exitCode): void
    {
        if ($recipe === null || $commandName === null || $command === null || $exitCode === null) {
            return;
        }

        $logFile = $this->config->getLogsDir() . "/recipe_history.log";
        $timestamp = date("Y-m-d H:i:s");
        $logEntry = sprintf(
            "[%s] Recipe: %s, Command: %s, Exit Code: %d\nCommand: %s\n\n",
            $timestamp,
            $recipe,
            $commandName,
            $exitCode,
            $command
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Update recipe status
     */
    private function updateRecipeStatus(?string $recipe, ?int $exitCode, ?string $commandName, ?string $recipePath): void
    {
        if ($recipe === null || $exitCode === null || $commandName === null || $recipePath === null) {
            return;
        }

        $statusFile = $this->config->getLogsDir() . "/recipe_status.yaml";
        $status = [];

        if (file_exists($statusFile)) {
            try {
                $status = Yaml::parseFile($statusFile) ?? [];
            } catch (\Exception $e) {
                // If we can't read the file, start with an empty array
            }
        }

        $status[$recipe] = [
            "exit_code" => $exitCode,
            "command" => $commandName,
            "timestamp" => date("Y-m-d H:i:s"),
            "recipe_path" => $recipePath
        ];

        try {
            file_put_contents($statusFile, Yaml::dump($status));
        } catch (\Exception $e) {
            // If we can't write to the file, just log the error
            error_log("Failed to update recipe status: " . $e->getMessage());
        }
    }
} 