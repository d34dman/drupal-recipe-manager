<?php

declare(strict_types=1);

namespace D34dman\DrupalRecipeManager\DTO;

/**
 * Data Transfer Object for recipe configuration.
 */
final class RecipeConfig
{
    /**
     * @param string $name The name of the recipe
     * @param array<string> $dependencies List of recipe dependencies
     */
    public function __construct(
        private readonly string $name,
        private readonly string $label,
        private readonly array $dependencies = [],
    ) {
    }
    
    public function getLabel(): string
    {
        return $this->label;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

} 