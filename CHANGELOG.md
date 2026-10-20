# Changelog

## [Unreleased]

### Removed

- Public method `voku\AgentLoopRunner\Workspace\WorkspaceLease::toArray()`, which had no caller in Runner or its consumers.

### Documentation

- `docs/execution-model.md` gains "Adopting a completed candidate", and README quick-start step 5 points to it. After `complete: true` the candidate exists only as uncommitted changes in the Run worktree, and `cleanup` refuses a worktree with changes (exit code `70`), so the documented quick start could not finish. The section gives the exact handoff: copy the candidate into a clean checkout of the base with `git add -A` plus `git diff --cached --binary <base> | git apply --index`, run `agent-loop finish` there, then reset the worktree and run `cleanup`. It also shows how to confirm that the checkout holds the governed candidate tree. Found in a real governed Runner dogfood (voku/agent-loop-runner#65); no behavior change.

## [0.1.7] - 2026-10-20

### Fixed

- A stage result can now cite a file the candidate deleted as evidence: the reference `deleted-file:<path>` is accepted only when the path no longer exists in the candidate workspace and does exist in the governed base commit, and is recorded as a `git-base-file:<path>` observation with the SHA-256 of the base blob. A still-present path, a missing governed base, or a path absent from the base is refused with `INVALID_STAGE_RESULT` (#62).

### Documentation

- `docs/execution-model.md` shows how a Contract declares worktree dependency preparation: list the step that provides dependencies (for example `composer install --no-interaction --no-progress`) as the first validation command. The builder may run it, and the deterministic `verify` stage runs it again in the Run worktree only; a gitignored `vendor/` does not change the candidate identity and the user's checkout is never prepared. Without the step, a `vendor/bin` command exits 127 and the Run stops at Attention. `doctor` inspects each command on its own and still reports `missing_worktree_dependency` for a path an earlier declared command provides (#61).

### Changed

- Require released `voku/agent-loop ^0.20.67`, whose review stages are told that the deterministic `verify` stage executes the Contract's required validation on the exact candidate, so a reviewer without validation permission no longer stops a correct Run at Attention for that alone (voku/agent-loop#813). Refresh the locked dependency graph accordingly.

## [0.1.6] - 2026-10-19

### Documentation

- `docs/execution-model.md` gains an "Attention and recovery" section: `WAITING_FOR_ATTENTION` and exit code 16, the Attention id in stderr and `status` `authority.attention_id`, Attention being resolved by `agent-loop` and never by Runner, and what `run`/`resume` reconcile after an interruption. The README CLI reference links to it. No behavior change.

### Added

- `agent-loop-runner abandon TASK --by <actor> --reason <text>` (voku/agent-loop-runner#45): explicit operator evidence that a recorded provider process no longer exists, for a Run wedged because the Runner was killed while a provider was running. A `process_started` attempt whose recorded identity is provably gone (pid absent or reused, and its process group empty) becomes `cancelled` with `abandoned_by`, `abandoned_reason` and `abandoned_at` in its process evidence, so the existing `cleanup` can retire it. A live, uninspectable or unrecorded identity, a different status, or an empty/oversized actor or reason is refused with the journal unchanged. It never starts a provider, submits a result or calls agent-loop, and a dirty worktree is still refused by `cleanup`. The runtime journal's process metadata allow-list gains only these three string fields.
- `doctor TASK` adds an advisory, read-only `validation` object for the current stage: per declared validation command `ok`, `missing_worktree_dependency` (relative `vendor/` path not tracked in the Contract base commit), or `not_inspectable` (shell syntax is never parsed), plus a note that coverage of mandatory CI gates such as PHPStan is not checked and must be listed in the Contract. `doctor` without a task, exit codes, and stage execution are unchanged; no dependencies are provisioned. `docs/execution-model.md` documents that validation commands must run inside the worktree without undeclared dependencies.
- `doctor` reports a `roles` object mapping every configured role to `<host-id>: available` or `<host-id>: unavailable`, using the same probe as the `host_<id>` keys, so roles whose host cannot run are visible before `run`. Existing keys and the exit code are unchanged.

### Fixed

- The deterministic `verify` stage is now run against the Run worktree that holds the governed candidate (read-only lease, same candidate check as every stage), so Loop executes the Contract's validation commands on the work the builder produced instead of reporting a validation-labelled `pass` without running them. A candidate that fails its declared validation now stops at `WAITING_FOR_ATTENTION` instead of `complete: true`. Requires released `voku/agent-loop ^0.20.65` (voku/agent-loop#802); older Loop releases ignore the workspace argument. The end-to-end fixtures now declare validation they can actually pass (`php -l src/Foo.php`) instead of `composer ci`.
- `status` no longer advertises `controls.cancel` for a process that `cancel` cannot signal (voku/agent-loop-runner#45). The projection only checked that `pid` and `process_fingerprint` were recorded, so a vanished or reused pid, or a process that is not its own process-group leader, still showed `cancel: true` and then failed with "owned process identity is stale". `status` and `cancel` now share one read-only check, `CancellableProcess`; `cancel` keeps its messages and exit codes, and `controls.cancel` remains a snapshot, not a guarantee.
- Headless Claude Code builders can run the Contract's own validation: for stages with `mayMutate`, the `claude` adapter adds one exact `--allowedTools 'Bash(<command>)'` rule per `requiredValidation` command of the approved Contract, next to `acceptEdits`. No wildcard, no bypass mode, no persistent settings. A command that cannot be represented as plain tokens (wildcards, quotes, parentheses, separators, redirections, expansions, commas, double or edge spaces, leading `-`, over 500 characters) fails the stage closed with `PERMISSION_UNREPRESENTABLE` before a process starts. Read-only stages get no Bash rules.
- Headless Claude Code builders can edit their isolated worktree: the `claude` adapter adds `--permission-mode acceptEdits` for stages Loop authorizes to mutate (`mayMutate`). Previously `claude -p` had no permission mode, every edit was refused and the stage came back `blocked`. Read-only stages are unchanged and no permission-bypass flag is introduced.

### Changed

- Require released `voku/agent-loop ^0.20.65` and refresh the locked coordinated dependency graph for candidate-bound deterministic validation.

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
