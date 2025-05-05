# Changelog

## 1.1.1

### Patch Changes

- Coding Standards fixes
- Refactor to use same display logic for both recipe and recipe:dependencies

## 1.1.0

### Added

- Recursive dependency status tracking
  - When a recipe is enabled, all its dependencies are automatically marked as enabled
  - New `enabled_by` status attribute to track which recipe enabled another recipe
  - Prevents infinite loops in dependency chains
- Improved dependency visualization
  - Better tree structure display
  - Clearer status indicators
  - Support for inverted dependency view

### Changed

- Status tracking improvements
  - More detailed status information
  - Better handling of nested dependencies
  - Clearer status indicators in the UI

### Fixed

- Dependency status updates
  - Fixed missing status updates for nested dependencies
  - Improved handling of circular dependencies
  - Better path handling for recipe dependencies

## 1.0.0

### Major Changes

- Initial release of Drupal Recipe Manager
- Interactive recipe selection with autocomplete
- Status tracking for recipes
- Configurable commands and variables
- Detailed execution logging
- Color-coded status indicators

---

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
