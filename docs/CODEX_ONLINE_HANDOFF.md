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

Before implementation, verify that the installed `voku/agent-loop` exposes the typed `voku\AgentLoop\Execution\ExecutionGateway` API from `voku/agent-loop#270`. If the dependency does not contain that API, stop and report the exact Composer/ref mismatch instead of recreating or scraping the protocol in Runner.

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

```text
load local runtime journal
-> fetch fresh ExecutionProjection from agent-loop
-> reconcile identities and current stage/attempt
-> prefer agent-loop on every disagreement
-> resume only the exact still-authorized action
```

The dangerous crash window is:

```text
StageResult persisted locally
-> submitStageResult()
-> agent-loop accepts/advances
-> Runner crashes before recording acceptance
```

Design this so the stage is not executed twice.

Required approach:

1. Allocate and durably persist a stable `submission_id` **before** the host process can produce a submit-ready result.
2. After process completion and candidate hashing, durably persist the exact full `StageResult` before calling `submitStageResult()`.
3. On resume, fetch fresh `ExecutionProjection` first.
4. If agent-loop has already advanced past that exact stage/attempt, mark the local attempt reconciled/accepted without rerunning the host.
5. If agent-loop still exposes that same stage/attempt and a complete persisted StageResult exists, resubmit that exact StageResult with the same `submission_id` before considering another host invocation. `agent-loop` makes duplicate identical submissions idempotent.
6. If task/Run/Contract/plan/stage/attempt identities conflict, stop with a machine-distinguishable stale-state failure. Never “repair” authority locally.

Use atomic writes for runtime JSON. A partially written journal must not be interpreted as valid state.

## Remaining implementation, in order

### 1. Establish a green executable package baseline

Create the missing binary and application layer:

```text
bin/agent-loop-runner
src/Application/
src/Runtime/
src/Execution/
tests/Unit/
tests/Integration/
tests/Fixtures/
```

Implement command routing and useful exit codes. `composer ci` must become green on PHP 8.3/8.4/8.5.

### 2. Completion-envelope parser

Implement a strict parser for the bundle-provided marker:

- use the final non-empty stdout line only;
- exact marker match;
- one JSON object on that line;
- allowed outcome must be one of `StageExecutionBundle::acceptedOutcomes`;
- summary must be bounded/non-empty where appropriate;
- artifact/validation references must be lists of non-empty strings;
- duplicate/multiple ambiguous markers fail;
- malformed JSON fails;
- output without a valid final envelope is `INVALID_STAGE_RESULT` even when process exit is zero.

Do not parse arbitrary prose for state.

### 3. `RunWorkspaceManager`

Build the Run-level owner around the existing Git primitives:

- exactly one worktree per Run;
- exclusive mutating lease;
- reusable sequential candidate tree across stages;
- lease identity includes task, Run, base commit, stage, attempt and mutation permission;
- stale/mismatched lease fails closed;
- no fallback to active checkout;
- cleanup only after safe state and never destroys dirty candidate evidence.

Add real temporary Git integration tests, including spaces/tabs/unusual filenames.

### 4. Runtime journal and reconciliation

Create typed Runner runtime records for at least:

```text
prepared
process_started
process_exited
result_persisted
submission_attempted
reconciled_accepted
waiting_for_attention
failed
cancelled
```

Do not mirror agent-loop's state machine. Store only Runner observations needed for exact restart.

Persist enough to diagnose/reconcile:

- task/run/contract revision/plan digest;
- stage/attempt;
- stable submission ID;
- host ID/version;
- workspace lease/base/candidate fingerprints;
- process PID/timestamps/exit/timeout;
- stdout/stderr log references;
- exact persisted StageResult when one exists.

Never persist allowlisted secret values.

### 5. Execution coordinator

Implement one orchestration path used by both `run` and `resume`:

```text
projection
-> Attention? stop
-> complete? stop successfully
-> deterministic stage? ask ExecutionGateway to run it
-> agent stage? prepare bundle
-> reconcile/create Run worktree
-> validate candidate workspace against bundle candidate
-> choose configured host for role
-> persist attempt/submission ID
-> execute process
-> persist logs/result observation
-> enforce mutation permission
-> parse completion envelope
-> compute/preserve candidate revision
-> persist exact StageResult
-> submit through ExecutionGateway
-> fetch/reconcile fresh projection
-> continue next authorized stage
```

No recursive unbounded loop. Make limits explicit even though profiles are finite.

### 6. CLI

Implement:

```bash
agent-loop-runner doctor
agent-loop-runner status TASK-123
agent-loop-runner run TASK-123
agent-loop-runner resume TASK-123
agent-loop-runner cancel TASK-123
agent-loop-runner cleanup TASK-123
```

Requirements:

- `doctor`: PHP/Git/config/agent-loop API/host availability; no mutation.
- `status`: projection + Runner observations, clearly distinguishing authority from observation.
- `run` and `resume`: same reconciliation engine.
- `cancel`: terminate only the owned process/process group, preserve logs/worktree, never mark stage passed.
- `cleanup`: fail on active process, Attention requiring evidence, dirty/unreconciled workspace, or uncertain ownership.

Machine-distinguishable failures:

```text
HOST_UNAVAILABLE
PROCESS_FAILED
PROCESS_TIMEOUT
INVALID_STAGE_RESULT
STALE_RUN
STALE_CONTRACT
STALE_WORKSPACE
TRANSITION_REJECTED
WAITING_FOR_ATTENTION
```

### 7. Diagnostic logs

Persist stdout and stderr separately below Runner-owned log paths. Runtime JSON should contain references and hashes/metadata, not giant transcripts or secrets.

Redact obvious credential-bearing environment metadata. Do not claim arbitrary model prose can be perfectly secret-scanned.

### 8. Tests first-class, not decorative

At minimum prove:

**Process**
- argv remains data under hostile-looking prompt content;
- missing binary;
- exit 0 and nonzero;
- timeout and descendant termination;
- stdout/stderr separation;
- Unicode and large output.

**Workspace**
- real temporary repository/worktree;
- exact base;
- dirty source checkout remains untouched;
- wrong repository;
- stale base;
- read-only mutation detection;
- untracked paths with spaces/tabs/newlines where Git/platform permit;
- symlink escape evidence;
- dirty cleanup refusal.

**Protocol/Runner**
- no pending stage;
- unavailable host;
- malformed completion result;
- Attention stops execution;
- process failure does not become pass;
- rejected StageResult;
- stale Contract/Run/plan/stage/attempt;
- crash before process start;
- crash after process exit;
- crash after result persistence but before submission;
- crash after agent-loop acceptance but before local acceptance persistence;
- exact identical resubmission rather than duplicate host execution.

**Security**
- no shell command construction;
- path traversal/project escape rejected;
- environment injection rejected;
- secret-like env values absent from runtime state;
- no Git push/merge/remote mutation;
- no permission-bypass defaults in adapters.

### 9. Profile E2E fixtures

Use deterministic fake host executables first so CI does not require provider credentials.

Prove complete real Git/Runner/agent-loop orchestration for:

```text
surgical:
  investigator -> builder -> reviewer -> verify

standard:
  investigator -> builder -> correctness-review
  -> blindspot-review -> verify

hardened:
  investigator -> builder -> correctness-review -> architecture-review
  -> hardening -> independent-verification -> blindspot-review -> verify
```

Exercise `CHANGES_REQUIRED`, clarification/Attention, process failure, and restart.

The deterministic `verify` stage remains executed by `agent-loop`, not by a provider adapter.

### 10. Real host smoke evidence

After deterministic CI is green, run disposable local fixture smoke tests against installed/available:

- Codex
- Claude Code
- OpenCode

A provider unavailable in the VM must be reported honestly; do not fabricate runtime evidence or weaken CI to fake availability. Adapter contract/unit tests remain mandatory regardless.

### 11. Installed-consumer dogfood

Create a clean disposable Composer consumer that installs Runner as a dependency and drives a governed fixture through the public binary/API only. Do not rely on repository-internal paths.

Then use Runner on one real `agent-loop`-style task/issue and preserve the exact evidence in the PR summary.

## Blind-spot analysis before implementation

Before editing, explicitly inspect and challenge at least these assumptions using repository/code/runtime evidence:

1. whether `ForegroundProcessSupervisor` really returns the child exit code after `proc_get_status()` polling on all supported PHP versions;
2. whether timeout process-group termination is safe when `setsid`/POSIX support differs;
3. whether host adapters' current argv still matches installed CLI versions in the VM;
4. whether `WorkspaceCandidateHasher` handles binary data and unusual path bytes without normalization bugs;
5. whether Runner can detect a read-only stage that modified the workspace without destroying the evidence;
6. whether local runtime atomicity is sufficient for every crash boundary;
7. whether cancellation can race with natural process exit;
8. whether the configured project root and bundle repository root can diverge or symlink-alias the same location;
9. whether a stale Runner worktree from a prior Run could be accidentally reused;
10. whether any proposed convenience would cause Runner to duplicate governance already owned by `agent-loop`.

Record only evidence-backed findings. Fix material issues inside this repo as part of the implementation.

## Commands to run repeatedly

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```

Use temporary repositories for integration tests. Do not depend on the developer's current checkout state.

## Explicit non-goals

Do not add:

- tmux dependency;
- dashboard/cockpit;
- parallel mutating agents;
- arbitrary DAG/workflow DSL;
- automatic model/profile selection;
- token/cost optimizer;
- remote worker fleet;
- Docker/Kubernetes orchestration;
- Git push, PR merge, auto-commit handoff, or release mutation;
- another durable Learning/Session/Kanban store;
- parsing of private `.agent-loop/**` artifacts;
- source copied from SwarmForge without separately verified licensing/provenance.

## Definition of done for this continuation

Do not stop at “implemented”. Continue until evidence proves:

- `composer ci` is green on supported PHP versions;
- Runner consumes only typed public `agent-loop` execution APIs;
- user's checkout stays untouched during integration/E2E tests;
- exactly one isolated worktree is reused per Run;
- process output never becomes workflow authority;
- exact-once restart behavior is tested around the acceptance crash window;
- clarification produces/observes Attention and execution stops;
- stale Contract/Run/plan/workspace fail closed;
- `surgical`, `standard`, and `hardened` profiles pass deterministic E2E fixtures;
- all three adapter contracts are production-tested, with real CLI smoke evidence when binaries are actually available;
- no push/merge/permission-bypass behavior exists by default;
- installed-consumer dogfood passes;
- architecture/security/restart/host-adapter docs match reality;
- PR summary names exact commands, environment, and any provider smoke test that could not be run.

If an upstream `agent-loop` defect is discovered, do **not** modify another repository from this task. Produce a minimal, reproducible blocker with the public API call, expected behavior, actual behavior and evidence, then stop only that blocked slice while completing all independent Runner work.
