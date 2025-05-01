<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Command;

use D34dman\DrupalRecipeManager\DTO\Config;
use D34dman\DrupalRecipeManager\DTO\RecipeExecutionStatus;
use D34dman\DrupalRecipeManager\DTO\RecipeStatus;
use D34dman\DrupalRecipeManager\Helper\RecipeDisplayHelper;
use D34dman\DrupalRecipeManager\Helper\RecipeManagerLogger;
use D34dman\DrupalRecipeManager\Helper\RecipeTreeFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class RecipeCommand extends Command
{
    protected static string $defaultName = 'recipe';

    protected static string $defaultDescription = 'List and run Drupal recipes';

    private Config $config;

    private Filesystem $filesystem;

    private RecipeTreeFinder $recipeTreeFinder;

    private RecipeManagerLogger $recipeLogger;

    private RecipeDisplayHelper $displayHelper;

    public function __construct(Config $config)
    {
        parent::__construct(self::$defaultName);
        $this->config = $config;
        $this->filesystem = new Filesystem();
        $this->recipeTreeFinder = new RecipeTreeFinder($config);
        $this->recipeLogger = new RecipeManagerLogger($config);
        $this->displayHelper = new RecipeDisplayHelper();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addArgument('recipe', InputArgument::OPTIONAL, 'The recipe to run')
            ->addOption('command', 'c', InputOption::VALUE_REQUIRED, 'The command to run (defaults to first configured command)')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List available recipes')
            ->addOption('scan-dirs', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directories to scan for recipes (comma-separated)')
            ->addOption('commands', 'm', InputOption::VALUE_REQUIRED, 'JSON string of commands configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Drupal Recipe Manager');

        // Set up signal handler for Ctrl+C
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(\SIGINT, function () use ($io) {
                $io->newLine(2);
                $io->writeln('<comment>Quitting Drupal Recipe Manager...</comment>');
                exit(0);
            });
        }

        // Override config with command line options if provided
        if ($scanDirs = $input->getOption('scan-dirs')) {
            $this->config->setScanDirs($scanDirs);
        }
        if ($commandsJson = $input->getOption('commands')) {
            $commands = json_decode($commandsJson, true);
            if (\JSON_ERROR_NONE !== json_last_error()) {
                $io->error('Invalid JSON format for commands option');

                return Command::FAILURE;
            }
            if (\is_array($commands)) {
                $this->config->setCommands($commands);
            }
        }

        // Find all recipe directories
        $recipes = $this->recipeTreeFinder->findAllRecipes($output);
        if (empty($recipes)) {
            $io->warning('No recipes found in configured directories.');

            return Command::FAILURE;
        }

        // Load recipe status
        $status = $this->loadRecipeStatus();

        // If --list is set, just show the list and exit
        if ($input->getOption('list')) {
            $io->section('Recipe Status Summary');
            $this->displaySummary($io, $recipes, $status);
            $io->section('Available Recipes');
            $this->displayHelper->displayRecipeList($io, $recipes, $status);

            return Command::SUCCESS;
        }

        // If a recipe is specified via command line, run it and exit
        if ($recipeName = $input->getArgument('recipe')) {
            return $this->runRecipe($io, $recipeName, $input->getOption('command'));
        }

        // Get the QuestionHelper
        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        // Interactive mode loop
        while (true) {
            // Clear screen for better readability
            $io->write("\033[2J\033[;H");

            // Load recipe status
            $status = $this->loadRecipeStatus();

            // Display recipe list before selection
            $this->displayHelper->displayRecipeList($io, $recipes, $status);

            // Show all recipes and let user select one
            $recipeName = $this->displayHelper->selectRecipe($io, $input, $output, $recipes, $status, $questionHelper);
            if (!$recipeName) {
                $io->writeln('<comment>Exiting...</comment>');

                return Command::SUCCESS;
            }

            // Find the recipe path in the list of recipes
            $recipePath = null;
            foreach ($recipes as $path) {
                if (basename($path) === $recipeName) {
                    $recipePath = $path;

                    break;
                }
            }

            if (!$recipePath) {
                $io->error("Recipe '{$recipeName}' not found");

                continue;
            }

            // Run the recipe
            $result = $this->runRecipe($io, $recipeName, $input->getOption('command'));
            if (Command::SUCCESS !== $result) {
                $io->error("Failed to run recipe '{$recipeName}'");
            }

            // Wait for user input before continuing
            $io->newLine();
            $io->writeln('<comment>Press Enter to continue or Ctrl+C to exit...</comment>');
            $question = new Question('');
            $questionHelper->ask($input, $output, $question);
        }
    }

    private function runRecipe(SymfonyStyle $io, string $recipeName, ?string $commandName): int
    {
        $commands = $this->config->getCommands();
        $commandName ??= array_key_first($commands);

        if (!isset($commands[$commandName])) {
            $io->error("Command '{$commandName}' not found in configuration");

            return Command::FAILURE;
        }

        $commandConfig = $commands[$commandName];
        $recipePath = $this->recipeTreeFinder->findRecipePath($recipeName);

        if (!$recipePath) {
            $io->error("Recipe '{$recipeName}' not found");

            return Command::FAILURE;
        }

        // Verify recipe.yml exists
        $recipeYmlPath = $recipePath . '/recipe.yml';
        if (!file_exists($recipeYmlPath)) {
            $io->error("Recipe file 'recipe.yml' not found in {$recipePath}");

            return Command::FAILURE;
        }

        // Prepare command with variables
        $command = $this->prepareCommand($commandConfig['command'], $recipePath);

        $io->section('Running Recipe');
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
                if (Process::ERR === $type) {
                    $io->write("<error>{$buffer}</error>");
                } else {
                    $io->write($buffer);
                }
            });

            // Log execution
            $this->recipeLogger->logExecution($recipeName, $commandName, $command, $process->getExitCode(), $recipePath);

            if (0 !== $process->getExitCode()) {
                $io->error("Command failed with exit code {$process->getExitCode()}");

                return Command::FAILURE;
            }

            $io->success('Recipe executed successfully');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Error executing command: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @return array<string, RecipeStatus>
     */
    private function loadRecipeStatus(): array
    {
        $statusFile = $this->config->getLogsDir() . '/recipe_status.yaml';
        if (!$this->filesystem->exists($statusFile)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($statusFile) ?? [];
            $status = [];
            foreach ($data as $recipe => $recipeData) {
                $status[$recipe] = RecipeStatus::fromArray($recipeData);
            }

            return $status;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @param array<string>               $recipes
     * @param array<string, RecipeStatus> $status
     */
    private function displaySummary(SymfonyStyle $io, array $recipes, array $status): void
    {
        $successCount = 0;
        $failedCount = 0;
        $notExecutedCount = 0;

        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeStatus = $status[$recipeName] ?? null;

            if (!$recipeStatus) {
                ++$notExecutedCount;
            } else {
                switch ($recipeStatus->getStatus()) {
                    case RecipeExecutionStatus::SUCCESS:
                        ++$successCount;

                        break;
                    case RecipeExecutionStatus::FAILED:
                        ++$failedCount;

                        break;
                    default:
                        ++$notExecutedCount;
                }
            }
        }

        $io->table(
            ['Status', 'Count'],
            [
                ['<fg=green>✓ Successfully executed</>', $successCount],
                ['<fg=red>✗ Failed executions</>', $failedCount],
                ['<fg=gray>○ Not executed yet</>', $notExecutedCount],
                ['<fg=blue>Total</>', \count($recipes)],
            ]
        );
    }

    private function prepareCommand(string $command, string $recipePath): string
    {
        // Ensure recipe path exists
        if (!is_dir($recipePath)) {
            throw new \RuntimeException("Recipe directory does not exist: {$recipePath}");
        }

        // Verify recipe.yml exists
        if (!file_exists($recipePath . '/recipe.yml')) {
            throw new \RuntimeException("Recipe file 'recipe.yml' not found in {$recipePath}");
        }

        $variables = [
            'folder' => $recipePath, // Use folder path only
            'folder_basename' => basename($recipePath),
            'folder_dirname' => \dirname($recipePath),
            'folder_relative' => $recipePath,
        ];

        // Apply custom transformations
        foreach ($this->config->getVariables() as $transform) {
            $inputValue = $variables[$transform['input']] ?? $transform['input'];
            $search = preg_quote($transform['search'], '/');
            $variables[$transform['name']] = preg_replace(
                '/' . $search . '/',
                $transform['replace'],
                (string) $inputValue
            );
        }

        // Replace all variables in command (handle both ${variable} and {${variable}} syntax)
        foreach ($variables as $key => $value) {
            $escapedValue = escapeshellarg((string) $value);
            // Replace ${variable}
            $command = str_replace('${' . $key . '}', $escapedValue, $command);
            // Replace {${variable}}
            $command = str_replace('{${' . $key . '}}', $escapedValue, $command);
        }

        // Debug: Log final command
        error_log('Final command: ' . $command);

        return $command;
    }
}
