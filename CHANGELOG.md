# Changelog

## [Unreleased]

### Added

- Add a clean installed-consumer CI proof that installs the Runner from its exact GitHub ref, requires released production dependencies, replays the generated consumer lock, and runs the installed `doctor` command in a fresh Git repository.

### Changed

- Integrate the hardened released `voku/agent-loop ^0.18.0` execution authority contract while preserving Runner-only execution observations, restart reconciliation, candidate/artifact submission, and typed application controls.

### Fixed

- Accept UTF-8 BOM-prefixed `.agent-loop-runner/config.json` files before JSON decoding, so editor-created configuration files load consistently.
