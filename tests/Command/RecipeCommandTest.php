<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\Tests\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use D34dman\DrupalRecipeManager\Command\RecipeCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Console\Command\Command;
use D34dman\DrupalRecipeManager\DTO\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \D34dman\DrupalRecipeManager\Command\RecipeCommand
 */
#[CoversClass(RecipeCommand::class)]
final class RecipeCommandTest extends TestCase
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

        // Create logs directory
        $logsDir = $this->testDir . "/logs";
        $this->filesystem->mkdir($logsDir);

        $this->createTestRecipe("test_recipe_1");
        $this->createTestRecipe("test_recipe_2");

        $this->config = new Config(
            scanDirs: [$this->testDir],
            logsDir: $logsDir,
            commands: [
                "drushRecipe" => [
                    "command" => 'echo ${folder}',
                    "requiresFolder" => true,
                ],
            ],
            variables: []
        );

        $this->application = new Application();
        $this->application->add(new RecipeCommand($this->config));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->testDir);
    }

    private function createTestRecipe(string $name, ?string $baseDir = null): void
    {
        $baseDir = $baseDir ?? $this->testDir;
        $recipeDir = $baseDir . "/" . $name;
        $this->filesystem->mkdir($recipeDir);

        $recipeConfig = [
            "name" => $name,
            "type" => "test"
        ];

        $configPath = $recipeDir . "/recipe.yml";
        $this->filesystem->dumpFile(
            $configPath,
            Yaml::dump($recipeConfig)
        );
    }

    #[Test]
    public function it_lists_recipes_with_list_option(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "--list" => true,
        ]);

        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString("Recipe Status Summary", $output);
        $this->assertStringContainsString("Available Recipes", $output);
        $this->assertStringContainsString("test_recipe_1", $output);
        $this->assertStringContainsString("test_recipe_2", $output);
    }

    #[Test]
    public function it_runs_recipe_with_command_option(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "test_recipe_1",
            "--command" => "drushRecipe",
        ]);

        $output = $commandTester->getDisplay();
        
        $this->assertStringContainsString("Running Recipe", $output);
        $this->assertStringContainsString("Recipe: test_recipe_1", $output);
        $this->assertStringContainsString("Command: echo", $output);
        $this->assertStringContainsString($this->testDir . "/test_recipe_1", $output);
    }

    #[Test]
    public function it_replaces_folder_variable_in_command(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "test_recipe_1",
            "--command" => "drushRecipe",
        ]);

        $output = $commandTester->getDisplay();
        
        // Get the actual command that was executed
        $actualCommand = "";
        if (preg_match('/Actual command: (.*?)$/m', $output, $matches)) {
            $actualCommand = trim($matches[1]);
        }

        // The folder path should be properly escaped and replaced
        $expectedFolderPath = escapeshellarg($this->testDir . "/test_recipe_1");
        
        $this->assertStringContainsString($expectedFolderPath, $actualCommand);
        
        // The command should not contain the ${folder} placeholder
        $this->assertStringNotContainsString('${folder}', $actualCommand);
        $this->assertStringNotContainsString('{folder}', $actualCommand);
    }

    #[Test]
    public function it_handles_invalid_recipe(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "nonexistent_recipe",
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("Recipe 'nonexistent_recipe' not found", $output);
    }

    #[Test]
    public function it_handles_invalid_command(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "test_recipe_1",
            "--command" => "invalid_command",
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("Command 'invalid_command' not found in configuration", $output);
    }

    #[Test]
    public function it_handles_invalid_json_commands(): void
    {
        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "--commands" => "invalid json",
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("Invalid JSON format for commands option", $output);
    }

    #[Test]
    public function it_handles_no_recipes_found(): void
    {
        // Remove test recipes
        $this->filesystem->remove($this->testDir . "/test_recipe_1");
        $this->filesystem->remove($this->testDir . "/test_recipe_2");

        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("No recipes found in configured directories", $output);
    }

    #[Test]
    public function it_handles_custom_scan_dirs(): void
    {
        // Create a new recipe in a different directory
        $customDir = $this->testDir . "/custom";
        $this->filesystem->mkdir($customDir);
        $this->createTestRecipe("custom_recipe", $customDir);

        $command = $this->application->find("recipe");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "--scan-dirs" => [$customDir],
            "--list" => true,
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString("custom_recipe", $output);
        $this->assertStringNotContainsString("test_recipe_1", $output);
        $this->assertStringNotContainsString("test_recipe_2", $output);
    }
} 