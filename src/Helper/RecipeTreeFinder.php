<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Helper;

use D34dman\DrupalRecipeManager\DTO\Config;
use D34dman\DrupalRecipeManager\DTO\RecipeConfig;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Helper class for finding and handling recipe trees.
 */
class RecipeTreeFinder
{
    /** @var array<string, int> */
    private array $visitCount = [];

    private Config $config;

    private Filesystem $filesystem;

    private readonly Finder $finder;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->filesystem = new Filesystem();
        $this->finder = new Finder();
    }

    /**
     * Find all recipe directories in configured paths.
     *
     * @return array<string>
     */
    public function findRecipes(?OutputInterface $output = null): array
    {
        $finder = new Finder();
        $recipes = [];
        $currentDir = getcwd();
        if (false === $currentDir) {
            throw new \RuntimeException('Could not get current working directory');
        }

        foreach ($this->config->getScanDirs() as $dir) {
            // Use relative path for directory
            $relativeDir = $dir;
            if (str_starts_with($dir, $currentDir)) {
                $relativeDir = substr($dir, \strlen($currentDir) + 1);
            }

            if (!$this->filesystem->exists($relativeDir)) {
                if ($output) {
                    $output->writeln("<comment>Directory not found: {$relativeDir}</comment>");
                }

                continue;
            }

            $finder->in($relativeDir)
                ->files()
                ->name('recipe.yml')
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->depth('>= 0');

            foreach ($finder as $file) {
                // Get relative path from current directory
                $recipePath = \dirname($file->getPathname());
                $recipeName = basename($recipePath);

                // Only add the recipe if it hasn't been added before
                if (!isset($recipes[$recipeName])) {
                    $recipes[$recipeName] = $recipePath;
                }
            }
        }

        // Convert associative array to indexed array for compatibility
        return array_values($recipes);
    }

    /**
     * @return array<string>
     */
    public function findAllRecipes(OutputInterface $output): array
    {
        $recipes = [];
        foreach ($this->config->getScanDirs() as $dir) {
            if ($this->filesystem->exists($dir)) {
                $finder = new Finder();
                $finder->directories()->in($dir)->depth(0);
                foreach ($finder as $file) {
                    $recipePath = $dir . '/' . $file->getFilename();
                    $recipes = array_merge($recipes, $this->findRecipes($output));
                }
            }
        }

        return array_unique($recipes);
    }

    public function loadRecipeConfig(string $recipePath): ?RecipeConfig
    {
        $configFile = $recipePath . '/recipe.yml';
        if (!$this->filesystem->exists($configFile)) {
            return null;
        }

        try {
            $config = Yaml::parseFile($configFile);
            if (!\is_array($config)) {
                return null;
            }

            return new RecipeConfig(
                name: basename($recipePath),
                label: $config['name'] ?? '',
                dependencies: $config['recipes'] ?? [],
            );
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Find a recipe by name in the configured scan directories.
     *
     * @param string $recipeName The name of the recipe to find
     *
     * @return null|string The path to the recipe directory or null if not found
     */
    public function findRecipePath(string $recipeName): ?string
    {
        // Create a new Finder instance to avoid caching issues
        $finder = new Finder();
        $finder->directories()
            ->in($this->config->getScanDirs())
            ->name($recipeName)
            ->depth(0);

        if (!$finder->hasResults()) {
            return null;
        }

        foreach ($finder as $dir) {
            return $dir->getPathname();
        }

        return null;
    }

    /**
     * Get dependencies for a specific recipe.
     *
     * @return array<string>
     */
    public function getRecipeDependencies(string $recipePath): array
    {
        $config = $this->loadRecipeConfig($recipePath);
        if (null === $config) {
            return [];
        }

        return $config->getDependencies();
    }

    /**
     * Check if a recipe has been visited (for circular dependency detection).
     */
    public function isVisited(string $recipeName): bool
    {
        $isVisited = isset($this->visitCount[$recipeName]) && $this->visitCount[$recipeName] > 0;

        return $isVisited;
    }

    /**
     * Mark a recipe as visited.
     */
    public function markVisited(string $recipeName): void
    {
        if (!isset($this->visitCount[$recipeName])) {
            $this->visitCount[$recipeName] = 0;
        }
        ++$this->visitCount[$recipeName];
    }

    /**
     * Remove a recipe from visited list.
     */
    public function unmarkVisited(string $recipeName): void
    {
        if (isset($this->visitCount[$recipeName])) {
            --$this->visitCount[$recipeName];
            if ($this->visitCount[$recipeName] <= 0) {
                unset($this->visitCount[$recipeName]);
            }
        }
    }

    /**
     * Find a recipe by name in the given directories.
     *
     * @param string        $recipeName The name of the recipe to find
     * @param array<string> $scanDirs   The directories to scan
     *
     * @return null|string The path to the recipe directory, or null if not found
     */
    public function findRecipePathInDirs(string $recipeName, array $scanDirs): ?string
    {
        // Create a new Finder instance to avoid caching issues
        $finder = new Finder();
        $finder->directories()
            ->in($scanDirs)
            ->name($recipeName)
            ->depth(0);

        if (!$finder->hasResults()) {
            return null;
        }

        foreach ($finder as $dir) {
            return $dir->getRealPath();
        }

        return null;
    }

    /**
     * Find all recipes in the given directories.
     *
     * @param array<string> $scanDirs The directories to scan
     *
     * @return array<string> List of recipe names
     */
    public function findAllRecipesInDirs(array $scanDirs): array
    {
        $this->finder->directories()
            ->in($scanDirs)
            ->depth(0);

        $recipes = [];
        foreach ($this->finder as $dir) {
            $recipes[] = $dir->getBasename();
        }

        return $recipes;
    }
}
