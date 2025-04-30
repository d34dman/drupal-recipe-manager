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

        // Update the current recipe's status
        $status[$recipe] = [
            'exit_code' => $exitCode,
            'command' => $commandName,
            'timestamp' => date('Y-m-d H:i:s'),
            'recipe_path' => $recipePath,
        ];

        // If the recipe was successfully enabled (exit code 0), update dependent recipes recursively
        if (0 === $exitCode) {
            $recipeTreeFinder = new RecipeTreeFinder($this->config);
            $visited = [$recipe => true]; // Track visited recipes to avoid infinite loops
            
            $this->updateDependentRecipesStatus(
                $recipeTreeFinder,
                $recipePath,
                $commandName,
                $status,
                $visited
            );
        }

        try {
            file_put_contents($statusFile, Yaml::dump($status));
        } catch (\Exception $e) {
            // If we can't write to the file, just log the error
            error_log('Failed to update recipe status: ' . $e->getMessage());
        }
    }

    /**
     * Recursively update status for all dependent recipes.
     *
     * @param RecipeTreeFinder $recipeTreeFinder The recipe tree finder instance
     * @param string $recipePath The path to the current recipe
     * @param string $commandName The command name that was executed
     * @param array<string, array<string, mixed>> $status The current status array
     * @param array<string, bool> $visited Set of visited recipe names to avoid infinite loops
     */
    private function updateDependentRecipesStatus(
        RecipeTreeFinder $recipeTreeFinder,
        string $recipePath,
        string $commandName,
        array &$status,
        array &$visited
    ): void {
        $dependencies = $recipeTreeFinder->getRecipeDependencies($recipePath);
        
        foreach ($dependencies as $dependency) {
            // Get the base name of the dependency (in case it's a full path)
            $dependencyName = basename($dependency);
            
            // Skip if we've already visited this dependency
            if (isset($visited[$dependencyName])) {
                continue;
            }
            
            // Find the dependency path using the recipe name
            $dependencyPath = $recipeTreeFinder->findRecipePath($dependencyName);
            if (null !== $dependencyPath) {
                // Mark as visited using the recipe name
                $visited[$dependencyName] = true;
                
                // Update dependent recipe status to enabled
                $status[$dependencyName] = [
                    'exit_code' => 0,
                    'command' => $commandName,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'recipe_path' => $dependencyPath,
                ];
                
                // Recursively update this dependency's dependencies
                $this->updateDependentRecipesStatus(
                    $recipeTreeFinder,
                    $dependencyPath,
                    $commandName,
                    $status,
                    $visited
                );
            }
        }
    }
}
