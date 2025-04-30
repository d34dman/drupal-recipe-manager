<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Helper;

use D34dman\DrupalRecipeManager\DTO\Config;
use Symfony\Component\Yaml\Yaml;

/**
 * Helper class for logging recipe execution.
 */
class RecipeManagerLogger
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Log recipe execution to history and update status.
     *
     * @param null|string $recipe      The recipe name
     * @param null|string $commandName The command name
     * @param null|string $command     The actual command executed
     * @param null|int    $exitCode    The command exit code
     * @param null|string $recipePath  The path to the recipe
     */
    public function logExecution(?string $recipe, ?string $commandName, ?string $command, ?int $exitCode, ?string $recipePath): void
    {
        if (null === $recipe || null === $commandName || null === $command || null === $exitCode || null === $recipePath) {
            return;
        }

        $this->logToHistory($recipe, $commandName, $command, $exitCode);
        $this->updateRecipeStatus($recipe, $exitCode, $commandName, $recipePath);
    }

    /**
     * Log execution to command history.
     */
    private function logToHistory(?string $recipe, ?string $commandName, ?string $command, ?int $exitCode): void
    {
        if (null === $recipe || null === $commandName || null === $command || null === $exitCode) {
            return;
        }

        $logFile = $this->config->getLogsDir() . '/recipe_history.log';
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = \sprintf(
            "[%s] Recipe: %s, Command: %s, Exit Code: %d\nCommand: %s\n\n",
            $timestamp,
            $recipe,
            $commandName,
            $exitCode,
            $command
        );

        file_put_contents($logFile, $logEntry, \FILE_APPEND);
    }

    /**
     * Update recipe status.
     */
    private function updateRecipeStatus(?string $recipe, ?int $exitCode, ?string $commandName, ?string $recipePath): void
    {
        if (null === $recipe || null === $exitCode || null === $commandName || null === $recipePath) {
            return;
        }

        $statusFile = $this->config->getLogsDir() . '/recipe_status.yaml';
        $status = [];

        if (file_exists($statusFile)) {
            try {
                $status = Yaml::parseFile($statusFile) ?? [];
            } catch (\Exception $e) {
                // If we can't read the file, start with an empty array
            }
        }

        $status[$recipe] = [
            'exit_code' => $exitCode,
            'command' => $commandName,
            'timestamp' => date('Y-m-d H:i:s'),
            'recipe_path' => $recipePath,
        ];

        try {
            file_put_contents($statusFile, Yaml::dump($status));
        } catch (\Exception $e) {
            // If we can't write to the file, just log the error
            error_log('Failed to update recipe status: ' . $e->getMessage());
        }
    }
}
