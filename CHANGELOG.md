# Changelog

## [Unreleased]

## [0.1.1] - 2026-09-05

### Changed

- Allow released `voku/agent-loop ^0.20.0` alongside the already-supported `^0.18.0` and `^0.19.0` lines. No Runner runtime behavior changes; this restores an installable released consumer graph for downstream packages such as `agent-ui` after Loop 0.20.0.

## [0.1.0] - 2026-09-04

### Changed

- Require the coordinated pre-1.0 release set through `voku/agent-loop ^0.19.0`.

### Added

- Add `AgyHostAdapter` supporting Google Antigravity (`agy`) CLI non-interactive execution (`--dangerously-skip-permissions -p`), discovery in `doctor`, and provider smoke testing.
- Add a clean installed-consumer CI proof that installs the Runner from its exact GitHub ref, requires released production dependencies, replays the generated consumer lock, and runs the installed `doctor` command in a fresh Git repository.

### Changed

- Integrate the hardened released `voku/agent-loop ^0.18.0` execution authority contract while preserving Runner-only execution observations, restart reconciliation, candidate/artifact submission, and typed application controls.
- Normalize artifact references in `WorkspaceArtifactObserver` to accept `path:line`, `path:line:col`, and `path:line-range` citations as well as the `workspace-file:` URI prefix without weakening path traversal guards.
- Tolerate model diagnostic `validation_references` in completion envelopes without failing the stage, maintaining the strict invariant that agent stages cannot mint authoritative validation evidence.

### Fixed

- Retire runtime journal records during `cleanup()`, preventing orphaned records from locking reconciliation with `STALE_RUN` after Contract revisions start a new Run.
- Accept UTF-8 BOM-prefixed `.agent-loop-runner/config.json` files before JSON decoding, so editor-created configuration files load consistently.
- Fix static analysis types in `WorkspaceArtifactObserverTest` for `StageOutcome` and non-empty-string artifact reference parameters.
