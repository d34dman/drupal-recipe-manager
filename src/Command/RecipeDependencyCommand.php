<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Command;

use D34dman\DrupalRecipeManager\DTO\Config;
use D34dman\DrupalRecipeManager\DTO\RecipeStatus;
use D34dman\DrupalRecipeManager\Helper\RecipeDisplayHelper;
use D34dman\DrupalRecipeManager\Helper\RecipeTreeFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class RecipeDependencyCommand extends Command
{
    protected static string $defaultName = 'recipe:dependencies';

    protected static string $defaultDescription = 'Show recipe dependencies in a tree structure';

    private RecipeTreeFinder $treeFinder;

    private Filesystem $filesystem;

    private Config $config;

    private RecipeDisplayHelper $displayHelper;

    /** @var array<string, array<string>> */
    private array $dependencyMap = [];

    public function __construct(Config $config)
    {
        parent::__construct(self::$defaultName);
        $this->config = $config;
        $this->treeFinder = new RecipeTreeFinder($config);
        $this->filesystem = new Filesystem();
        $this->displayHelper = new RecipeDisplayHelper();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addArgument('recipe', InputArgument::OPTIONAL, 'The recipe to show dependencies for')
            ->addOption('inverted', 'i', null, 'Show inverted dependency tree (which recipes depend on this recipe)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recipe Dependencies');

        // Ensure UTF-8 output
        if (method_exists($output, 'setEncoding')) {
            $output->setEncoding('UTF-8');
        }

        // Find all recipe directories
        $recipes = $this->treeFinder->findRecipes($output);
        if (empty($recipes)) {
            $io->warning('No recipes found in configured directories.');

            return Command::FAILURE;
        }

        // Build dependency map for inverted tree
        $this->buildDependencyMap($recipes);

        // If a recipe is specified via command line, use it directly
        if ($recipeName = $input->getArgument('recipe')) {
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

                return Command::FAILURE;
            }

            if ($input->getOption('inverted')) {
                $this->displayInvertedDependencyTree($io, $recipeName);
            } else {
                $this->displayDependencyTree($io, $recipePath, 0, [], $output);
            }

            return Command::SUCCESS;
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

            // Display recipe list
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

            // Display dependency tree
            if ($input->getOption('inverted')) {
                $this->displayInvertedDependencyTree($io, $recipeName);
            } else {
                $this->displayDependencyTree($io, $recipePath, 0, [], $output);
            }

            // Wait for user input before continuing
            $io->newLine();
            $io->writeln('<comment>Press Enter to continue or Ctrl+C to exit...</comment>');
            $question = new Question('');
            $questionHelper->ask($input, $output, $question);
        }
    }

    /**
     * @param array<string> $recipes
     */
    private function buildDependencyMap(array $recipes): void
    {
        foreach ($recipes as $recipePath) {
            $recipeName = basename($recipePath);
            $dependencies = $this->treeFinder->getRecipeDependencies($recipePath);

            foreach ($dependencies as $dependency) {
                $depName = basename($dependency);
                if (!isset($this->dependencyMap[$depName])) {
                    $this->dependencyMap[$depName] = [];
                }
                $this->dependencyMap[$depName][] = $recipeName;
            }
        }
    }

    private function displayInvertedDependencyTree(SymfonyStyle $io, string $recipeName, int $depth = 0): void
    {
        // Check for circular dependencies
        if ($this->treeFinder->isVisited($recipeName)) {
            $io->writeln(str_repeat('  ', $depth) . "└─ <error>Circular dependency detected: {$recipeName}</error>");

            return;
        }

        $this->treeFinder->markVisited($recipeName);

        // Display current recipe
        $prefix = 0 === $depth ? '' : str_repeat('  ', $depth - 1) . '└─ ';
        $io->writeln($prefix . "<info>{$recipeName}</info>");

        // Get and display recipes that depend on this one
        $dependents = $this->dependencyMap[$recipeName] ?? [];
        foreach ($dependents as $dependent) {
            $this->displayInvertedDependencyTree($io, $dependent, $depth + 1);
        }

        $this->treeFinder->unmarkVisited($recipeName);
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
     * @param array<string> $parentConnectors
     */
    private function displayDependencyTree(
        SymfonyStyle $io,
        string $recipePath,
        int $depth = 0,
        array $parentConnectors = [],
        ?OutputInterface $output = null
    ): void {
        $recipeName = basename($recipePath);

        // Check for circular dependencies
        if ($this->treeFinder->isVisited($recipeName)) {
            $this->writeTreeLine($io, $parentConnectors, "└── <error>Circular dependency detected: {$recipeName}</error>");

            return;
        }

        $this->treeFinder->markVisited($recipeName);

        // Display current recipe only if it's the root
        if (0 === $depth) {
            $io->writeln("<info>{$recipeName}</info>");
        }

        // Get and display dependencies
        $dependencies = $this->treeFinder->getRecipeDependencies($recipePath);
        $lastIndex = \count($dependencies) - 1;

        foreach ($dependencies as $index => $dependency) {
            // Handle both full paths and recipe names
            $depName = basename($dependency);

            // Skip if this is the same as the current recipe
            if ($depName === $recipeName) {
                continue;
            }

            // Find the dependency path
            $depPath = $this->treeFinder->findRecipePath($depName);
            if (!$depPath) {
                continue;
            }

            // Create connectors for this level
            $currentConnectors = $parentConnectors;

            // Display the dependency name with proper connector
            $connector = $index === $lastIndex ? '└── ' : '├── ';
            $this->writeTreeLine($io, $currentConnectors, $connector . "<info>{$depName}</info>");

            // Prepare connectors for the next level
            $nextConnectors = $currentConnectors;
            $nextConnectors[] = $index === $lastIndex ? '    ' : '│   ';
            $this->displayDependencyTree($io, $depPath, $depth + 1, $nextConnectors, $output);
        }

        $this->treeFinder->unmarkVisited($recipeName);
    }

    /**
     * @param array<string> $connectors
     */
    private function writeTreeLine(SymfonyStyle $io, array $connectors, string $content): void
    {
        $prefix = implode('', $connectors);
        $io->writeln($prefix . $content);
    }
}
