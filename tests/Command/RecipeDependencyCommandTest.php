<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use D34dman\DrupalRecipeManager\Command\RecipeDependencyCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Command\Command;
use D34dman\DrupalRecipeManager\DTO\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \D34dman\DrupalRecipeManager\Command\RecipeDependencyCommand
 */
#[CoversClass(RecipeDependencyCommand::class)]
final class RecipeDependencyCommandTest extends TestCase
{
    private string $testDir;
    private Filesystem $filesystem;
    private Config $config;
    private Application $application;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . "/drupal-recipe-manager-test";
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testDir);

        $this->createTestRecipe("test_recipe_1", ["test_recipe_2"]);
        $this->createTestRecipe("test_recipe_2");

        $this->config = new Config(
            scanDirs: [$this->testDir],
            logsDir: $this->testDir . "/logs",
            commands: [
                "drushRecipe" => [
                    "command" => "drush recipe {folder} -v",
                    "requiresFolder" => true,
                ],
            ],
            variables: []
        );

        $this->application = new Application();
        $this->application->add(new RecipeDependencyCommand($this->config));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    private function createTestRecipe(string $name, array $dependencies = []): void
    {
        $recipeDir = $this->testDir . "/" . $name;
        $this->filesystem->mkdir($recipeDir);

        $recipeConfig = [
            "name" => $name,
            "type" => "test",
            "recipes" => $dependencies
        ];

        $configPath = $recipeDir . "/recipe.yml";
        $this->filesystem->dumpFile(
            $configPath,
            Yaml::dump($recipeConfig)
        );

        // Verify the file was created and contains the correct content
        if (!$this->filesystem->exists($configPath)) {
            throw new \RuntimeException("Failed to create recipe config file: {$configPath}");
        }

        $configContent = file_get_contents($configPath);
        if ($configContent === false) {
            throw new \RuntimeException("Failed to read recipe config file: {$configPath}");
        }

        $parsedConfig = Yaml::parse($configContent);
        if (!is_array($parsedConfig) || !isset($parsedConfig["recipes"])) {
            throw new \RuntimeException("Invalid recipe config content: {$configPath}");
        }
    }

    #[Test]
    public function it_shows_dependencies_for_a_recipe(): void
    {
        $command = $this->application->find("recipe:dependencies");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "test_recipe_1",
        ]);

        $output = $commandTester->getDisplay();
        
        // Debug output
        echo "\nTest output:\n" . $output . "\n";
        
        // Verify recipe files exist
        $recipe1Path = $this->testDir . "/test_recipe_1/recipe.yml";
        $recipe2Path = $this->testDir . "/test_recipe_2/recipe.yml";
        
        if (!$this->filesystem->exists($recipe1Path)) {
            throw new \RuntimeException("Recipe 1 config file not found: {$recipe1Path}");
        }
        if (!$this->filesystem->exists($recipe2Path)) {
            throw new \RuntimeException("Recipe 2 config file not found: {$recipe2Path}");
        }
        
        // Verify recipe 1 dependencies
        $recipe1Config = Yaml::parseFile($recipe1Path);
        if (!is_array($recipe1Config) || !isset($recipe1Config["recipes"])) {
            throw new \RuntimeException("Invalid recipe 1 config content: {$recipe1Path}");
        }
        if (!in_array("test_recipe_2", $recipe1Config["recipes"])) {
            throw new \RuntimeException("Recipe 1 does not have test_recipe_2 as a dependency");
        }

        $this->assertStringContainsString("Recipe Dependencies", $output);
        $this->assertStringContainsString("test_recipe_1", $output);
        $this->assertStringContainsString("└── test_recipe_2", $output);
    }

    #[Test]
    public function it_handles_invalid_recipe(): void
    {
        $input = new ArrayInput([
            "recipe" => "nonexistent_recipe",
        ]);
        $output = new BufferedOutput();

        $command = $this->application->find("recipe:dependencies");
        $command->run($input, $output);

        $this->assertStringContainsString("Recipe 'nonexistent_recipe' not found", $output->fetch());
    }
} 