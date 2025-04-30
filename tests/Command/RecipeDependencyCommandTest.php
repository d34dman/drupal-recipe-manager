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

/**
 * @covers \D34dman\DrupalRecipeManager\Command\RecipeDependencyCommand
 */
#[CoversClass(RecipeDependencyCommand::class)]
final class RecipeDependencyCommandTest extends TestCase
{
    private string $testDir;
    private Filesystem $filesystem;
    private array $config;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . "/drupal-recipe-manager-test";
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->testDir);

        $this->createTestRecipe("test_recipe_1", ["test_recipe_2"]);
        $this->createTestRecipe("test_recipe_2");

        $this->config = [
            "scanDirs" => [$this->testDir]
        ];
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

        $this->filesystem->dumpFile(
            $recipeDir . "/recipe.yml",
            Yaml::dump($recipeConfig)
        );
    }

    #[Test]
    public function it_shows_dependencies_for_a_recipe(): void
    {
        $application = new Application();
        $application->add(new RecipeDependencyCommand($this->config));

        $command = $application->find("recipe:dependencies");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "test_recipe_1"
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertStringContainsString("test_recipe_1", $commandTester->getDisplay());
        $this->assertStringContainsString("test_recipe_2", $commandTester->getDisplay());
    }

    #[Test]
    public function it_handles_nonexistent_recipe(): void
    {
        $application = new Application();
        $application->add(new RecipeDependencyCommand($this->config));

        $command = $application->find("recipe:dependencies");
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            "recipe" => "nonexistent_recipe"
        ]);

        $this->assertSame(1, $commandTester->getStatusCode());
        $this->assertStringContainsString("Recipe 'nonexistent_recipe' not found", $commandTester->getDisplay());
    }
} 