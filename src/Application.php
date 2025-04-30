<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager;

use D34dman\DrupalRecipeManager\Command\RecipeCommand;
use D34dman\DrupalRecipeManager\Command\RecipeDependencyCommand;
use D34dman\DrupalRecipeManager\DTO\Config;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class Application extends BaseApplication
{
    private const NAME = 'Drupal Recipe Manager';

    private const VERSION = '1.0.0';

    private Filesystem $filesystem;

    private string $configPath;

    private Config $config;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->filesystem = new Filesystem();
        // Load configuration
        $this->config = $this->loadConfig();
        // Register commands
        $this->add(new RecipeCommand($this->config));
        $this->add(new RecipeDependencyCommand($this->config));
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        if ($output && $output->isVerbose()) {
            $currentDir = getcwd();
            $output->writeln('<comment>Debug: Environment Information</comment>');
            $output->writeln(\sprintf('  - Current directory: %s', $currentDir));
            $output->writeln(\sprintf('  - Config path: %s', $this->configPath));
            $output->writeln(\sprintf('  - Scan directories: %s', implode(', ', $this->config->getScanDirs())));
            $output->writeln('');
        }

        return parent::run($input, $output);
    }

    protected function getDefaultCommands(): array
    {
        $defaultCommands = parent::getDefaultCommands();
        $defaultCommands[] = new RecipeDependencyCommand($this->config);

        return $defaultCommands;
    }

    private function loadConfig(): Config
    {
        $configFile = $this->findConfigFile();
        if (null !== $configFile) {
            try {
                $fileConfig = Yaml::parseFile($configFile);
                if (\is_array($fileConfig)) {
                    return Config::fromArray($fileConfig);
                }
            } catch (\Exception $e) {
                // Silently ignore configuration file errors
            }
        }

        return Config::fromArray([
            'scanDirs' => [
                './recipes',
            ],
            'logsDir' => './logs',
            'commands' => [
                'drushRecipe' => [
                    'command' => 'drush recipe ${folder} -v',
                    'requiresFolder' => true,
                ],
            ],
            'variables' => [],
        ]);
    }

    /**
     * Find configuration file in current directory.
     *
     * @return null|string Path to configuration file or null if not found
     */
    private function findConfigFile(): ?string
    {
        $currentDir = getcwd();
        if (false === $currentDir) {
            return null;
        }

        $possibleFiles = [
            $currentDir . '/drupal-recipe-manager.yaml',
            $currentDir . '/drupal-recipe-manager.yml',
        ];

        foreach ($possibleFiles as $file) {
            if ($this->filesystem->exists($file)) {
                $this->configPath = $file;

                return $file;
            }
        }

        return null;
    }
}
