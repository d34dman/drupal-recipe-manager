<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Helper;

use D34dman\DrupalRecipeManager\DTO\RecipeExecutionStatus;
use D34dman\DrupalRecipeManager\DTO\RecipeStatus;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class RecipeDisplayHelper
{
    /**
     * @param array<string>                    $recipes
     * @param array<string, null|RecipeStatus> $status
     */
    public function displayRecipeList(SymfonyStyle $io, array $recipes, array $status): void
    {
        // Sort recipes by status (Not executed -> Failed -> Successful)
        usort($recipes, function ($a, $b) use ($status) {
            $statusA = $status[basename($a)] ?? null;
            $statusB = $status[basename($b)] ?? null;

            // Get status codes (0 = success, 1 = failed, 2 = not executed)
            $codeA = $this->getStatusCode($statusA);
            $codeB = $this->getStatusCode($statusB);

            // First sort by status (2 -> 1 -> 0)
            if ($codeA !== $codeB) {
                return $codeB <=> $codeA; // Reverse order to get 2,1,0
            }

            // Then sort by last run timestamp within each status group
            $timeA = $statusA?->getTimestamp() ?? '0';
            $timeB = $statusB?->getTimestamp() ?? '0';

            return strtotime($timeB) <=> strtotime($timeA);
        });

        // Create table rows
        $rows = [];
        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;
            $statusIcon = '○';
            $statusColor = 'gray';
            $lastRun = 'Never';

            if ($recipeStatus) {
                switch ($recipeStatus->getStatus()) {
                    case RecipeExecutionStatus::SUCCESS:
                        $statusIcon = '✓';
                        $statusColor = 'green';

                        break;
                    case RecipeExecutionStatus::FAILED:
                        $statusIcon = '✗';
                        $statusColor = 'red';

                        break;
                    case RecipeExecutionStatus::NOT_EXECUTED:
                        $statusIcon = '○';
                        $statusColor = 'gray';

                        break;
                }

                if ($recipeStatus->getTimestamp()) {
                    $lastRun = $this->formatLastRun($recipeStatus->getTimestamp());
                }
            }

            $rows[] = [
                "<fg={$statusColor}>{$statusIcon}</>",
                "<fg={$statusColor}>{$recipeName}</>",
                "<fg={$statusColor}>{$lastRun}</>",
            ];
        }

        // Display table
        $io->table(
            ['Status', 'Recipe', 'Last Run'],
            $rows
        );
    }

    /**
     * @param array<string>                    $recipes
     * @param array<string, null|RecipeStatus> $status
     */
    public function selectRecipe(
        SymfonyStyle $io,
        InputInterface $input,
        OutputInterface $output,
        array $recipes,
        array $status,
        QuestionHelper $questionHelper
    ): ?string {
        // Sort recipes by status (Not executed -> Failed -> Successful)
        usort($recipes, function ($a, $b) use ($status) {
            $statusA = $status[basename($a)] ?? null;
            $statusB = $status[basename($b)] ?? null;

            // Get status codes (0 = success, 1 = failed, 2 = not executed)
            $codeA = $this->getStatusCode($statusA);
            $codeB = $this->getStatusCode($statusB);

            // First sort by status (2 -> 1 -> 0)
            if ($codeA !== $codeB) {
                return $codeB <=> $codeA; // Reverse order to get 2,1,0
            }

            // Then sort by last run timestamp within each status group
            $timeA = $statusA?->getTimestamp() ?? '0';
            $timeB = $statusB?->getTimestamp() ?? '0';

            return strtotime($timeB) <=> strtotime($timeA);
        });

        // Create a map of recipe names to their full paths and display strings
        $recipeMap = [];
        $displayMap = [];
        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;
            $statusIcon = '○';
            $statusColor = 'gray';

            if ($recipeStatus) {
                switch ($recipeStatus->getStatus()) {
                    case RecipeExecutionStatus::SUCCESS:
                        $statusIcon = '✓';
                        $statusColor = 'green';

                        break;
                    case RecipeExecutionStatus::FAILED:
                        $statusIcon = '✗';
                        $statusColor = 'red';

                        break;
                    case RecipeExecutionStatus::NOT_EXECUTED:
                        $statusIcon = '○';
                        $statusColor = 'gray';

                        break;
                }
            }

            $recipeMap[$recipeName] = $recipe;
            $displayMap[$recipeName] = "<fg={$statusColor}>{$statusIcon} {$recipeName}</>";
        }

        // Get the first recipe name as default
        $firstRecipe = array_key_first($recipeMap);

        // Create autocomplete suggestions (plain recipe names for matching)
        $suggestions = array_keys($recipeMap);

        // Create a custom question with autocomplete and default value
        $question = new Question("Search and select a recipe [<comment>{$firstRecipe}</comment>]: ", $firstRecipe);
        $question->setAutocompleterValues($suggestions);
        $question->setValidator(function ($value) use ($recipeMap) {
            // Handle empty input or null
            if (null === $value || '' === $value) {
                return null;
            }

            // Validate recipe exists
            if (!isset($recipeMap[$value])) {
                throw new \RuntimeException("Recipe not found: {$value}");
            }

            return $value;
        });

        $selected = $questionHelper->ask($input, $output, $question);

        if (null === $selected) {
            return null;
        }

        // Display the selected recipe with its colored format
        $io->writeln($displayMap[$selected]);

        return $selected;
    }

    private function getStatusCode(?RecipeStatus $status): int
    {
        if (!$status) {
            return 2; // Not executed
        }

        return match ($status->getStatus()) {
            RecipeExecutionStatus::SUCCESS => 0,
            RecipeExecutionStatus::FAILED => 1,
            RecipeExecutionStatus::NOT_EXECUTED => 2,
        };
    }

    private function formatLastRun(?string $timestamp): string
    {
        if (!$timestamp) {
            return 'Never';
        }

        $time = strtotime($timestamp);
        if (false === $time) {
            return 'Never';
        }

        return date('Y-m-d H:i:s', $time);
    }
}
