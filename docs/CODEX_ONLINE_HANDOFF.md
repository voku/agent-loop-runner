# Codex Online handoff: finish the governed Runner

This document is the self-contained continuation point for `voku/agent-loop-runner#1`.

The intended executor is a fresh Codex Online run with a real Linux/PHP VM and access to **this repository only**. Do not depend on prior chat context.

## Start state

Work in repository `voku/agent-loop-runner` on branch `feature/governed-runner` (or the PR branch that contains this file).

The one-way architecture is fixed:

```text
agent-loop-runner
      ↓
  agent-loop
```

`agent-loop` is the governance owner. Runner is an optional execution plane. Never add a reverse dependency and do not edit `voku/agent-loop` from this task.

The typed execution protocol from `voku/agent-loop#270` is released in `voku/agent-loop ^0.17.0`, which is the Runner's minimum owner contract. Before implementation, verify that the installed released package exposes `voku\AgentLoop\Execution\ExecutionGateway`. If it does not, stop and report the exact Composer/version mismatch instead of switching to `dev-main`, recreating the protocol, or scraping private state.

## What already exists

Do not rewrite these merely to make the code feel more locally authored.

### Package/config

- PHP `^8.3`, strict types, PHPUnit 11.5, PHPStan max.
- `RunnerLayout` owns `.agent-loop-runner/config.json`, `runtime/`, `worktrees/`, and `logs/` paths.
- `Config/RunnerConfig.php` owns explicit role-to-host mapping, timeout configuration, and environment-variable **name** allowlisting. Secret values are runtime inputs and must not be persisted.

### Process boundary

- `Process/ProcessRequest.php`
- `Process/ProcessResult.php`
- `Process/ProcessSupervisor.php`
- `Process/ForegroundProcessSupervisor.php`
- `Process/EnvironmentProjector.php`

The foreground supervisor uses argv arrays rather than shell-concatenated commands, captures stdout/stderr independently, enforces a timeout, and uses a separate process group on supported Unix systems so descendants can be terminated.

### Host adapters

The adapters are intentionally thin translation layers:

- Codex: `codex exec --ephemeral -`, prompt via stdin.
- Claude Code: `claude -p <prompt>`.
- OpenCode: `opencode run <prompt>`.

Relevant files are under `src/Host/`.

Do not add model names, reasoning knobs, `--yolo`, dangerous bypass flags, implicit permission elevation, or provider-specific workflow authority. Provider exit code/output are observations only.

### Git/workspace primitives

- `Git/GitCommand.php`
- `Git/GitCommandResult.php`
- `Workspace/GitWorktreeService.php`
- `Workspace/WorkspaceLease.php`
- `Workspace/WorkspaceCandidateHasher.php`

Current invariants:

- one isolated detached Git worktree per governed Run;
- exact Git base commit required;
- workspace must belong to the same Git common directory as the source repository;
- agent-created commits are rejected; `HEAD` stays at the governed base;
- Runner never mutates the user's source checkout;
- dirty candidate work is never silently reset or deleted;
- volatile Runner paths are repository-locally ignored without forcing `config.json` to be ignored;
- candidate hashing covers exact base commit, full tracked binary diff, NUL-safe untracked paths, and untracked file/symlink contents.

`WorkspaceCandidateHasher::hash()` returns:

```text
git-worktree-v1:<base-commit>:sha256:<digest>
```

## Authoritative agent-loop protocol

Consume the typed API only. Never parse `.agent-loop/**`, human CLI output, Recall files, or terminal prose to reconstruct workflow truth.

The expected API surface includes:

```php
$gateway = new ExecutionGateway($projectRoot);
$projection = $gateway->projection($taskId);
$bundle = $gateway->prepareStage($taskId, $stageId);
$projection = $gateway->submitStageResult($stageResult);
$projection = $gateway->resolveAttention($taskId, $attentionId);
$projection = $gateway->runDeterministicStage($taskId, 'verify');
```

`StageExecutionBundle` is the only execution input authority supplied to Runner. It contains the exact task/Run/Contract/plan/stage/attempt binding, role, mutation permission, canonical repository root, captured Git base, prior candidate revision, scope, validation requirements, prior handoff, accepted outcomes, prompt and completion marker.

For agent stages the final non-empty host-output line must use the exact marker published by the bundle:

```text
AGENT_LOOP_STAGE_RESULT {"outcome":"...","summary":"...","artifact_references":[],"validation_references":[]}
```

Runner parses this as a candidate result. It does **not** infer success from exit `0`, prose such as “tests passed”, or file presence.

## Candidate revision reconciliation

This distinction matters:

- Initial `StageExecutionBundle::candidateRevision` may be the exact base commit.
- After a mutating stage, Runner publishes `WorkspaceCandidateHasher::hash(...)` as the new candidate revision.
- Read-only stages must leave the candidate unchanged. If a read-only host changes the worktree, fail closed and preserve evidence; never bless that mutation by silently updating the candidate.
- When the authoritative candidate is still the base commit, require a clean worktree.
- When the authoritative candidate is a `git-worktree-v1:` value, recompute the workspace hash and require exact equality before continuing.

## The critical exactly-once invariant

Runner-local state is restart bookkeeping, never governance truth.

On every `run` and `resume`:
