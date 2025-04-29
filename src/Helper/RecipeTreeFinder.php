<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Helper;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Helper class for finding and handling recipe trees.
 */
class RecipeTreeFinder
{
    private array $config;
    private Filesystem $filesystem;
    private array $recipeCache = [];
    private array $visitedRecipes = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->filesystem = new Filesystem();
    }

    /**
     * Find all recipe directories in configured paths.
     */
    public function findRecipes(OutputInterface $output): array
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

    /**
     * Find a specific recipe path by name.
     */
    public function findRecipePath(string $recipe, array $recipes): ?string
    {
        foreach ($recipes as $path) {
            if (basename($path) === $recipe) {
                return $path;
            }
        }
        return null;
    }

    /**
     * Get dependencies for a specific recipe.
     */
    public function getRecipeDependencies(string $recipePath): array
    {
        $recipeYmlPath = $recipePath . "/recipe.yml";
        if (!file_exists($recipeYmlPath)) {
            return [];
        }

        $recipeData = Yaml::parseFile($recipeYmlPath);
        return $recipeData["recipes"] ?? [];
    }

    /**
     * Check if a recipe has been visited (for circular dependency detection).
     */
    public function isVisited(string $recipeName): bool
    {
        return in_array($recipeName, $this->visitedRecipes);
    }

    /**
     * Mark a recipe as visited.
     */
    public function markVisited(string $recipeName): void
    {
        $this->visitedRecipes[] = $recipeName;
    }

    /**
     * Remove a recipe from visited list.
     */
    public function unmarkVisited(): void
    {
        array_pop($this->visitedRecipes);
    }
} 