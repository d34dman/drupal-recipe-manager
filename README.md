# Drupal Recipe Manager

A Symfony-based CLI tool for managing and executing Drupal recipes. This tool provides an interactive interface to list, search, and run Drupal recipes with proper status tracking and logging.

## Features

- Interactive recipe selection with autocomplete
- Status tracking for recipes (Not executed, Failed, Successful)
- Real-time progress display during recipe execution
- Configurable commands and variables
- Detailed execution logging
- Color-coded status indicators
- Easy exit with Ctrl+C

## Installation

1. Install globally using Composer:
```bash
composer global require d34dman/drupal-recipe-manager
```

2. Create a `drupal-recipe-manager.yaml` file in your project root with the following configuration:

```yaml
# Directories to scan for recipes
scanDirs:
  - recipes

# Logs directory (relative to scripts directory)
logsDir: logs

# Custom commands for recipe management
commands:
  ddev:
    description: "Run Drush recipe command using ddev"
    command: "ddev drush recipe ${ddevRecipePath}"
  local:
    description: "Run Drush recipe command"
    command: "drush recipe ${folder}"

# Variable transformations
variables:
  - name: "ddevRecipePath"
    input: "${folder}"
    search: "^.*?recipes/"
    replace: "../recipes/"
  - name: "recipeName"
    input: "${folder_basename}"
    search: "-"
    replace: "_"

```

## Usage

### Basic Commands

1. List available recipes:
```bash
bin/drupal-recipe-manager --list
```

2. Run a specific recipe:
```bash
bin/drupal-recipe-manager recipe_name
```

3. Run a recipe with a specific command:
```bash
bin/drupal-recipe-manager recipe_name --command=command_name
```

### Interactive Mode

Run without arguments to enter interactive mode:
```bash
bin/drupal-recipe-manager
```

In interactive mode:
- Use arrow keys or type to search for recipes
- Press Enter to select the highlighted recipe
- Press Ctrl+C to exit at any time

## Configuration Options

- `scanDirs`: List of directories to scan for recipes
- `logsDir`: Directory for storing logs and status files
- `commands`: Custom commands for running recipes
- `variables`: Variable transformations for command templates

## Status Indicators

- ○ Gray: Recipe not executed yet
- ✗ Red: Recipe execution failed
- ✓ Green: Recipe executed successfully

## Logs and Status

The tool maintains two log files:
1. `logs/recipe_status.yaml`: Tracks the status of each recipe
2. `logs/command_history.yaml`: Records all command executions

## Project Structure

```
drupal-recipe-manager/
├── bin/
│   └── drupal-recipe-manager
├── src/
│   └── Command/
│       └── RecipeCommand.php
├── config/
├── logs/
├── composer.json
└── README.md
```

## Requirements

- PHP 8.1 or higher
- Composer
- Symfony Console component
- Drupal installation with recipes

## License

This project is licensed under the MIT License. 