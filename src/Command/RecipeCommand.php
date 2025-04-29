<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

class RecipeCommand extends Command
{
    protected static $defaultName = "recipe";
    protected static $defaultDescription = "List and run Drupal recipes";

    private array $config;
    private string $logsDir;
    private Filesystem $filesystem;

    public function __construct(array $config, string $logsDir)
    {
        parent::__construct();
        $this->config = $config;
        $this->logsDir = $logsDir;
        $this->filesystem = new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addArgument("recipe", InputArgument::OPTIONAL, "The recipe to run")
            ->addOption("command", "c", InputOption::VALUE_REQUIRED, "The command to run (defaults to first configured command)")
            ->addOption("list", "l", InputOption::VALUE_NONE, "List available recipes");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Drupal Recipe Manager");

        // Set up signal handler for Ctrl+C
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use ($io) {
                $io->newLine(2);
                $io->writeln("<comment>Quitting Drupal Recipe Manager...</comment>");
                exit(0);
            });
        }

        // Find all recipe directories
        $recipes = $this->findRecipes($output);
        if (empty($recipes)) {
            $io->warning("No recipes found in configured directories.");
            return Command::FAILURE;
        }

        // Load recipe status
        $status = $this->loadRecipeStatus();

        // If --list is set, just show the list and exit
        if ($input->getOption("list")) {
            $this->displaySummary($io, $recipes, $status);
            $this->displayRecipeList($io, $recipes, $status);
            return Command::SUCCESS;
        }

        // If a recipe is specified via command line, run it and exit
        if ($recipeName = $input->getArgument("recipe")) {
            return $this->runRecipe($io, $recipeName, $input->getOption("command"), $recipes);
        }

        // Interactive mode
        while (true) {
            // Clear screen and show recipe list
            $output->write("\033[2J\033[1;1H"); // ANSI escape sequence to clear screen
            $io->title("Drupal Recipe Manager");
            $this->displaySummary($io, $recipes, $status);
            $this->displayRecipeList($io, $recipes, $status);

            // Show quit option
            $io->writeln("\n<comment>Press Ctrl+C to exit</comment>");

            // Ask user to select a recipe
            $recipeName = $this->selectRecipe($io, $input, $output, $recipes, $status);
            if (!$recipeName) {
                break; // User selected Exit
            }

            // If only one command is defined, use it automatically
            if (count($this->config["commands"]) === 1) {
                $commandName = array_key_first($this->config["commands"]);
                $io->writeln(sprintf("Using command: <info>%s</info>", $commandName));
                $this->runRecipe($io, $recipeName, $commandName, $recipes);
            } else {
                // Otherwise, show command selection
                $commandName = $this->selectCommand($io, $input, $output);
                if ($commandName) {
                    $this->runRecipe($io, $recipeName, $commandName, $recipes);
                }
            }

            // Reload status after command execution
            $status = $this->loadRecipeStatus();

            // Ask if user wants to continue
            if (!$io->confirm("Do you want to run another recipe?", true)) {
                break;
            }
        }

        return Command::SUCCESS;
    }

    private function runRecipe(SymfonyStyle $io, string $recipeName, ?string $commandName, array $recipes): int
    {
        $commandName = $commandName ?? array_key_first($this->config["commands"]);

        if (!isset($this->config["commands"][$commandName])) {
            $io->error("Command '{$commandName}' not found in configuration");
            return Command::FAILURE;
        }

        $commandConfig = $this->config["commands"][$commandName];
        $recipePath = $this->findRecipePath($recipeName, $recipes);

        if (!$recipePath) {
            $io->error("Recipe '{$recipeName}' not found");
            return Command::FAILURE;
        }

        // Verify recipe.yml exists
        $recipeYmlPath = $recipePath . "/recipe.yml";
        if (!file_exists($recipeYmlPath)) {
            $io->error("Recipe file 'recipe.yml' not found in {$recipePath}");
            return Command::FAILURE;
        }

        // Prepare command with variables
        $command = $this->prepareCommand($commandConfig["command"], $recipePath);

        $io->section("Running Recipe");
        $io->writeln("Recipe: <info>{$recipeName}</info>");
        $io->writeln("Command: <info>{$command}</info>");
        
        // Log the actual command being executed
        $io->writeln("Actual command: <comment>{$command}</comment>");

        try {
            // Execute command
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(null);
            $process->setWorkingDirectory($recipePath);
            
            $process->run(function ($type, $buffer) use ($io) {
                if ($type === Process::ERR) {
                    $io->write("<error>{$buffer}</error>");
                } else {
                    $io->write($buffer);
                }
            });

            // Log execution
            $this->logExecution($recipeName, $commandName, $command, $process->getExitCode());

            if ($process->getExitCode() !== 0) {
                $io->error("Command failed with exit code {$process->getExitCode()}");
                return Command::FAILURE;
            }

            $io->success("Recipe executed successfully");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error executing command: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function selectRecipe(SymfonyStyle $io, InputInterface $input, OutputInterface $output, array $recipes, array $status): ?string
    {
        // Sort recipes by status (Not executed -> Failed -> Successful)
        usort($recipes, function($a, $b) use ($status) {
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
            $timeA = $statusA["timestamp"] ?? "0";
            $timeB = $statusB["timestamp"] ?? "0";
            
            return strtotime($timeB) <=> strtotime($timeA);
        });

        // Create a map of recipe names to their full paths and display strings
        $recipeMap = [];
        $displayMap = [];
        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;
            $statusIcon = "○";
            $statusColor = "gray";
            
            if ($recipeStatus) {
                $exitCode = $recipeStatus["exit_code"] ?? null;
                if ($exitCode === 0) {
                    $statusIcon = "✓";
                    $statusColor = "green";
                } elseif ($exitCode !== null) {
                    $statusIcon = "✗";
                    $statusColor = "red";
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
            if ($value === null || $value === "") {
                return null;
            }
            
            // Validate recipe exists
            if (!isset($recipeMap[$value])) {
                throw new \RuntimeException("Recipe not found: {$value}");
            }
            
            return $value;
        });

        $helper = $this->getHelper("question");
        $selected = $helper->ask($input, $output, $question);

        if ($selected === null) {
            return null;
        }

        // Display the selected recipe with its colored format
        $io->writeln($displayMap[$selected]);

        return $selected;
    }

    private function selectCommand(SymfonyStyle $io, InputInterface $input, OutputInterface $output): ?string
    {
        $commandChoices = array_keys($this->config["commands"]);
        $commandChoices[] = "Back";
        
        $defaultCommand = $commandChoices[0];
        $commandQuestion = new ChoiceQuestion(
            "Select a command to run [<comment>{$defaultCommand}</comment>]:",
            $commandChoices,
            0  // Preselect first command option
        );
        $commandQuestion->setErrorMessage("Command %s is invalid.");
        
        $helper = $this->getHelper("question");
        $selectedCommand = $helper->ask($input, $output, $commandQuestion);
        
        return $selectedCommand === "Back" ? null : $selectedCommand;
    }

    private function findRecipePath(string $recipe, array $recipes): ?string
    {
        foreach ($recipes as $recipePath) {
            if (basename($recipePath) === $recipe) {
                return $recipePath;
            }
        }
        return null;
    }

    private function findRecipes(OutputInterface $output): array
    {
        $finder = new Finder();
        $recipes = [];
        $currentDir = getcwd();

        foreach ($this->config["scanDirs"] as $dir) {
            // Use relative path for directory
            $relativeDir = $dir;
            if (strpos($dir, $currentDir) === 0) {
                $relativeDir = substr($dir, strlen($currentDir) + 1);
            }
            
            if (!$this->filesystem->exists($relativeDir)) {
                continue;
            }

            $finder->in($relativeDir)
                ->files()
                ->name("recipe.yml")
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->depth(">= 0");

            foreach ($finder as $file) {
                // Get relative path from current directory
                $recipePath = dirname($file->getPathname());
                $recipes[] = $recipePath;
            }
        }

        return $recipes;
    }

    private function loadRecipeStatus(): array
    {
        $statusFile = $this->logsDir . "/recipe_status.yaml";
        if (!$this->filesystem->exists($statusFile)) {
            return [];
        }

        try {
            return Yaml::parseFile($statusFile) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function displaySummary(SymfonyStyle $io, array $recipes, array $status): void
    {
        $successCount = 0;
        $failedCount = 0;
        $notExecutedCount = 0;

        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;
            
            if (!$recipeStatus) {
                $notExecutedCount++;
            } else {
                $exitCode = $recipeStatus["exit_code"] ?? null;
                if ($exitCode === 0) {
                    $successCount++;
                } elseif ($exitCode !== null) {
                    $failedCount++;
                } else {
                    $notExecutedCount++;
                }
            }
        }

        $io->section("Recipe Status Summary");
        $io->table(
            ["Status", "Count"],
            [
                ["<fg=green>✓ Successfully executed</>", $successCount],
                ["<fg=red>✗ Failed executions</>", $failedCount],
                ["<fg=gray>○ Not executed yet</>", $notExecutedCount],
                ["<fg=blue>Total</>", count($recipes)]
            ]
        );
    }

    private function displayRecipeList(SymfonyStyle $io, array $recipes, array $status): void
    {
        $io->section("Available Recipes");

        // Sort recipes by status (Not executed -> Failed -> Successful)
        usort($recipes, function($a, $b) use ($status) {
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
            $timeA = $statusA["timestamp"] ?? "0";
            $timeB = $statusB["timestamp"] ?? "0";
            
            return strtotime($timeB) <=> strtotime($timeA);
        });

        $rows = [];
        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;

            $statusIcon = "○";
            $statusColor = "gray";
            $lastRun = "Never";

            if ($recipeStatus) {
                $exitCode = $recipeStatus["exit_code"] ?? null;
                if ($exitCode === 0) {
                    $statusIcon = "✓";
                    $statusColor = "green";
                } elseif ($exitCode !== null) {
                    $statusIcon = "✗";
                    $statusColor = "red";
                }

                if (isset($recipeStatus["timestamp"])) {
                    $date = new \DateTime($recipeStatus["timestamp"]);
                    $lastRun = $date->format("Y-m-d H:i");
                }
            }

            $rows[] = [
                "<fg={$statusColor}>{$statusIcon}</>",
                "<fg={$statusColor}>{$recipeName}</>",
                $lastRun
            ];
        }

        $io->table(
            ["Status", "Recipe", "Last Run"],
            $rows
        );
    }

    /**
     * Get status code for sorting (0 = success, 1 = failed, 2 = not executed)
     */
    private function getStatusCode(?array $status): int
    {
        if (!$status) {
            return 2; // Not executed
        }
        
        $exitCode = $status["exit_code"] ?? null;
        if ($exitCode === 0) {
            return 0; // Success
        } elseif ($exitCode !== null) {
            return 1; // Failed
        }
        
        return 2; // Not executed
    }

    private function prepareCommand(string $command, string $recipePath): string
    {
        // Ensure recipe path exists
        if (!is_dir($recipePath)) {
            throw new \RuntimeException("Recipe directory does not exist: {$recipePath}");
        }

        // Verify recipe.yml exists
        if (!file_exists($recipePath . "/recipe.yml")) {
            throw new \RuntimeException("Recipe file 'recipe.yml' not found in {$recipePath}");
        }

        $variables = [
            "folder" => $recipePath, // Use folder path only
            "folder_basename" => basename($recipePath),
            "folder_dirname" => dirname($recipePath),
            "folder_relative" => $recipePath,
            "ddevRecipePath" => $recipePath
        ];

        // Apply custom transformations
        foreach ($this->config["variables"] ?? [] as $transform) {
            $inputValue = $variables[$transform["input"]] ?? $transform["input"];
            $search = preg_quote($transform["search"], "/");
            $variables[$transform["name"]] = preg_replace(
                "/" . $search . "/",
                $transform["replace"],
                $inputValue
            );
        }

        // Special handling for Drush recipe commands
        if (strpos($command, "drush recipe") !== false) {
            // Handle both ddevRecipe and drushRecipe commands
            $escapedPath = escapeshellarg($recipePath);
            
            // Replace ${folder} and {${folder}}
            $command = str_replace('${folder}', $escapedPath, $command);
            $command = str_replace('{${folder}}', $escapedPath, $command);
            
            // Replace ${ddevRecipePath} and {${ddevRecipePath}}
            $command = str_replace('${ddevRecipePath}', $escapedPath, $command);
            $command = str_replace('{${ddevRecipePath}}', $escapedPath, $command);
            
            // Debug: Log the command after replacement
            error_log("Command after recipe path replacement: " . $command);
        }

        // Replace remaining variables in command (handle both ${variable} and {${variable}} syntax)
        foreach ($variables as $key => $value) {
            if ($key !== "folder" && $key !== "ddevRecipePath") { // Skip already handled variables
                // Replace ${variable}
                $command = str_replace('${' . $key . '}', $value, $command);
                // Replace {${variable}}
                $command = str_replace('{${' . $key . '}}', $value, $command);
            }
        }

        // Debug: Log final command
        error_log("Final command: " . $command);

        return $command;
    }

    private function logExecution(string $recipe, string $commandName, string $command, int $exitCode): void
    {
        $timestamp = (new \DateTime())->format("c");
        $status = $exitCode === 0 ? "success" : "failed";

        // Log to command history
        $historyFile = $this->logsDir . "/command_history.yaml";
        $historyEntry = [
            "timestamp" => $timestamp,
            "recipe" => $recipe,
            "command" => $commandName,
            "actual_command" => $command,
            "exit_code" => $exitCode,
            "status" => $status
        ];

        $this->appendYaml($historyFile, $historyEntry);

        // Update recipe status
        $statusFile = $this->logsDir . "/recipe_status.yaml";
        $statusData = $this->filesystem->exists($statusFile) ? 
            Yaml::parseFile($statusFile) ?? [] : [];

        $statusData[$recipe] = [
            "executed" => true,
            "exit_code" => $exitCode,
            "timestamp" => $timestamp,
            "directory" => $this->findRecipePath($recipe, [$recipe])
        ];

        $this->filesystem->dumpFile($statusFile, Yaml::dump($statusData));
    }

    private function appendYaml(string $file, array $data): void
    {
        $content = "";
        if ($this->filesystem->exists($file)) {
            $content = file_get_contents($file);
            if (!empty($content)) {
                $content .= "\n---\n";
            }
        }

        $content .= Yaml::dump($data);
        $this->filesystem->dumpFile($file, $content);
    }
} 