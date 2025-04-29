<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Command;

use D34dman\DrupalRecipeManager\Helper\RecipeTreeFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;

class RecipeDependencyCommand extends Command
{
    protected static $defaultName = "recipe:dependencies";
    protected static $defaultDescription = "Show recipe dependencies in a tree structure";

    private array $config;
    private RecipeTreeFinder $treeFinder;
    private array $dependencyMap = [];

    public function __construct(array $config)
    {
        parent::__construct(self::$defaultName);
        $this->config = $config;
        $this->treeFinder = new RecipeTreeFinder($config);
    }

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription(self::$defaultDescription)
            ->addArgument("recipe", InputArgument::OPTIONAL, "The recipe to show dependencies for")
            ->addOption("inverted", "i", null, "Show inverted dependency tree (which recipes depend on this recipe)");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Recipe Dependencies");

        // Find all recipe directories
        $recipes = $this->treeFinder->findRecipes($output);
        if (empty($recipes)) {
            $io->warning("No recipes found in configured directories.");
            return Command::FAILURE;
        }

        // Build dependency map for inverted tree
        $this->buildDependencyMap($recipes);

        // If a recipe is specified, show its dependencies
        if ($recipeName = $input->getArgument("recipe")) {
            $recipePath = $this->treeFinder->findRecipePath($recipeName, $recipes);
            if (!$recipePath) {
                $io->error("Recipe '{$recipeName}' not found");
                return Command::FAILURE;
            }

            if ($input->getOption("inverted")) {
                $this->displayInvertedDependencyTree($io, $recipeName);
            } else {
                $this->displayDependencyTree($io, $recipePath, 0, [], $output);
            }
            return Command::SUCCESS;
        }

        // Show all recipes and let user select one
        $recipeName = $this->selectRecipe($io, $recipes);
        if (!$recipeName) {
            return Command::SUCCESS;
        }

        $recipePath = $this->treeFinder->findRecipePath($recipeName, $recipes);
        if ($input->getOption("inverted")) {
            $this->displayInvertedDependencyTree($io, $recipeName);
        } else {
            $this->displayDependencyTree($io, $recipePath, 0, [], $output);
        }

        return Command::SUCCESS;
    }

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
            $io->writeln(str_repeat("  ", $depth) . "└─ <error>Circular dependency detected: {$recipeName}</error>");
            return;
        }

        $this->treeFinder->markVisited($recipeName);
        
        // Display current recipe
        $prefix = $depth === 0 ? "" : str_repeat("  ", $depth - 1) . "└─ ";
        $io->writeln($prefix . "<info>{$recipeName}</info>");

        // Get and display recipes that depend on this one
        $dependents = $this->dependencyMap[$recipeName] ?? [];
        foreach ($dependents as $dependent) {
            $this->displayInvertedDependencyTree($io, $dependent, $depth + 1);
        }

        $this->treeFinder->unmarkVisited();
    }

    private function selectRecipe(SymfonyStyle $io, array $recipes): ?string
    {
        $recipeMap = [];
        foreach ($recipes as $recipe) {
            $recipeName = basename($recipe);
            $recipeMap[$recipeName] = $recipe;
        }

        $question = new ChoiceQuestion(
            "Select a recipe to show dependencies:",
            array_keys($recipeMap)
        );

        return $io->askQuestion($question);
    }

    private function displayDependencyTree(
        SymfonyStyle $io,
        string $recipePath,
        int $depth = 0,
        array $parentConnectors = [],
        ?OutputInterface $output = null
    ): void
    {
        $recipeName = basename($recipePath);
        
        // Check for circular dependencies
        if ($this->treeFinder->isVisited($recipeName)) {
            $this->writeTreeLine($io, $parentConnectors, "└── <error>Circular dependency detected: {$recipeName}</error>");
            return;
        }

        $this->treeFinder->markVisited($recipeName);
        
        // Display current recipe only if it's the root
        if ($depth === 0) {
            $io->writeln("<info>{$recipeName}</info>");
        }

        // Get and display dependencies
        $dependencies = $this->treeFinder->getRecipeDependencies($recipePath);
        $lastIndex = count($dependencies) - 1;
        
        foreach ($dependencies as $index => $dependency) {
            // Handle both full paths and recipe names
            $depName = basename($dependency);
            $depPath = $this->treeFinder->findRecipePath($depName, $this->treeFinder->findRecipes($output));
            
            // Create connectors for this level
            $currentConnectors = $parentConnectors;
            
            // Display the dependency name with proper connector
            $connector = $index === $lastIndex ? "└── " : "├── ";
            $this->writeTreeLine($io, $currentConnectors, $connector . "<info>{$depName}</info>");
            
            if ($depPath) {
                // Prepare connectors for the next level
                $nextConnectors = $currentConnectors;
                $nextConnectors[] = $index === $lastIndex ? "    " : "│   ";
                $this->displayDependencyTree($io, $depPath, $depth + 1, $nextConnectors, $output);
            }
        }

        $this->treeFinder->unmarkVisited();
    }

    private function writeTreeLine(SymfonyStyle $io, array $connectors, string $content): void
    {
        $prefix = implode("", $connectors);
        $io->writeln($prefix . $content);
    }
} 