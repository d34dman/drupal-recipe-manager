<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use D34dman\DrupalRecipeManager\Command\RecipeCommand;

class Application extends BaseApplication
{
    private array $config;
    private Filesystem $filesystem;
    private string $configPath;
    private string $logsDir;

    public function __construct()
    {
        parent::__construct("Drupal Recipe Manager", "1.0.0");

        // Load configuration
        $this->loadConfig();

        // Register commands
        $this->add(new RecipeCommand($this->config, $this->logsDir));
    }

    private function loadConfig(): void
    {
        $filesystem = new Filesystem();
        $configFile = getcwd() . "/drupal-recipe-manager.yaml";

        if (!$filesystem->exists($configFile)) {
            throw new \RuntimeException("Configuration file not found: {$configFile}");
        }

        $this->config = Yaml::parseFile($configFile);
        $this->logsDir = $this->config["logsDir"] ?? "logs";

        // Ensure logs directory exists
        if (!$filesystem->exists($this->logsDir)) {
            $filesystem->mkdir($this->logsDir);
        }
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getLogsDir(): string
    {
        return $this->logsDir;
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        if ($output && $output->isVerbose()) {
            $currentDir = getcwd();
            $output->writeln("<comment>Debug: Environment Information</comment>");
            $output->writeln(sprintf("  - Current directory: %s", $currentDir));
            $output->writeln(sprintf("  - Config path: %s", $this->configPath));
            $output->writeln(sprintf("  - Logs directory: %s", $this->logsDir));
            $output->writeln(sprintf("  - Scan directories: %s", implode(", ", $this->config["scanDirs"])));
            $output->writeln("");
        }

        return parent::run($input, $output);
    }
} 