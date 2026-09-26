# Changelog

## [Unreleased]

## [0.1.5] - 2026-09-26

### Added

- Attest agent-loop fresh-context requirements with a bounded opaque `context_id` derived from the observed process PID, start time, and optional `/proc` process fingerprint.
- Preserve context identity across normal restart reconciliation and prove that distinct process attempts yield distinct identities while deliberately reused process identity yields the same identity.
- Require released `voku/agent-loop ^0.20.45` and refresh the locked coordinated dependency graph.

### Changed

- Preserve durable `ProcessStarted` identity evidence for stages that declare `context_id_required`, while leaving unrelated stages on the existing HostAdapter contract.
- Exclude task, Run, stage, role, host label, attempt, and submission identity from the context hash so workflow metadata cannot manufacture a false fresh-context claim.
- Keep the runtime claim deliberately process-level; it does not assert absence of hidden provider/account-global state.

### Validation

- PHP 8.3, 8.4, and 8.5: PHPUnit and PHPStan green.
- Installed-consumer proof green on PHP 8.3 and 8.5, including released dependency resolution, generated-lock replay, and installed Runner doctor.
- Restart, distinct-process, and same-process reuse proofs cover the context-lineage semantics.


## [0.1.4] - 2026-09-21

### Added

- Add configurable provider capacity probes with JSON/text parsing, reset-time observations, and quota-limit detection for coding-agent hosts.
- Route near-limit stages to configured role or host fallbacks, or stop before worktree creation with an explanatory `QUOTA_LIMIT_REACHED` message.
- Add quota enforcement coverage and the `quota-dogfood.php` self-dogfood tool.

### Changed

- Keep provider capacity and fallback decisions in Runner runtime observations without changing `agent-loop` workflow authority.

## [0.1.3] - 2026-09-20

### Added

- Add optional per-role model and reasoning-effort policies for Codex host execution, while keeping workflow authority and execution topology in `agent-loop`.
- Require the released typed `agent-loop 0.20.28` execution-owner API used by the Runner integration proof.
- Keep the exact-owner CI proof aligned with the current released `voku/agent-loop 0.20.29`.

### Fixed

- Refresh the CI exact-owner proof to the currently released `voku/agent-loop 0.20.28`.

## [0.1.2] - 2026-09-13

### Added

- Connect `RunnerControlService::status()` to `RequiredHostPreflight` via `CurrentExecutionStageReader` to gate `run` and `resume` when the exact host required for the current execution stage is missing, stale, or mismatched.
- Model `RequiredHostObservation` containing the required host, availability, and readiness reason.

### Changed

- Require released `voku/agent-loop ^0.20.7` and lock dependencies against the released 0.20.7 VCS tag.
- Allow clean recovery and cleanup of abandoned failed provider executions without corrupting stage state.


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
