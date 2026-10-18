# Execution and restart model

Runner observations progress through `prepared`, `process_started`, `process_exited`, `result_persisted`, `submission_attempted`, `reconciled_accepted`, `waiting_for_attention`, `failed`, and `cancelled`. These labels do not duplicate agent-loop's state machine.

Every `run`/`resume` takes a nonblocking per-task Runner execution lock before reconciliation, then reloads the typed `ExecutionProjection`. Run, Contract revision, plan digest, stage, attempt, base, and candidate mismatches fail closed. Read-only stages must preserve the candidate hash. Attention stops execution. Process exit zero, prose, and file presence never imply acceptance.

Runtime JSON uses a same-directory temporary file, flush/`fsync()` where available, restrictive permissions, and atomic same-filesystem rename. Corrupt or partial JSON is rejected rather than repaired. This provides atomic visibility and process-crash/restart safety for the supported execution model. PHP does not expose a portable parent-directory `fsync`, so the Runner does **not** claim that the rename itself is durable across sudden kernel/power loss on every platform. A total power loss after host execution but before the journal directory entry is durably committed is outside the exactly-once guarantee; after normal process failure/restart, reconciliation always consults fresh `agent-loop` authority before another host invocation.


## Process context lineage

When an agent-loop stage bundle declares `context_id_required`, Runner derives a
bounded opaque context id from the observed process PID, `started_at`, and the
optional `/proc` process fingerprint. Stage, role, host label, attempt, and
submission identity are deliberately excluded: those workflow values differ by
construction and therefore cannot prove process separation. The id is
deterministic for one already-executed process, so restoring a persisted
`StageResult` after a normal Runner restart keeps exactly the same context
lineage. A genuinely new observed process gets a different identity; if the
same observed process were reused by another stage, it would get the same id and
Loop's `fresh_required` authority would reject it.

This is deliberately a **process-level** claim. It proves that Runner did not
satisfy an independent-review stage by continuing the predecessor stage inside
the same supervised process attempt. It does not claim that a provider has no
hidden account-global, server-side, cache, or model state.

## Validation commands run inside the worktree

Every governed Run executes in an isolated Git worktree created from the Contract base commit, so it contains tracked files only. Untracked, ignored, and generated directories of the user's checkout, notably `vendor/`, are not present, and Runner does not symlink, copy, or install them.

Invariant: a Contract's validation commands must be executable inside that worktree without undeclared dependencies. A command that needs `vendor/bin/...` can only pass if the dependency is tracked or the Contract itself declares the step that provides it. List every mandatory gate, including PHPStan, in the Contract; Runner does not infer gates from CI configuration.

`agent-loop-runner doctor TASK` gives an advisory, read-only check of the current stage's declared validation commands, read through `ExecutionGateway` against the Contract base commit. Each command is reported as `ok`, `missing_worktree_dependency` (with the relative `vendor/` path that is not tracked in the base commit), or `not_inspectable` (with a reason; commands that are not plain space-separated tokens are never parsed). The `validation` object always carries a note that coverage of mandatory CI gates is not checked. These are warnings only: they do not change exit codes, stage execution, or validation acceptance.

## Attention and recovery

**`WAITING_FOR_ATTENTION`.** When the freshly loaded `ExecutionProjection` carries an Attention, `run` and `resume` stop before any host invocation. They write `WAITING_FOR_ATTENTION: <attention-id>` to stderr and exit with code `16` (`ExitCode::WAITING_FOR_ATTENTION`). Runner starts no process and submits nothing while the Attention is open. The check runs on every reconciliation iteration, so an Attention raised mid-run also stops execution.

**Where the id is visible.** The Attention id is in the stderr message above and in `agent-loop-runner status TASK` as `authority.attention_id` (`null` when there is no Attention). Both come from the Loop projection.

**Who resolves it.** Attention is owned and resolved by `agent-loop` (`workflow attention --resolve`). Runner never resolves, clears, or works around an Attention; its journal label `waiting_for_attention` is only an observation. After Loop resolves the Attention, run `resume TASK` (or `run TASK`).

**What `run`/`resume` do after an interruption.** Both take the per-task execution lock and enter the same reconciliation. It handles an Attention stop and a Runner restart after the provider process has exited:

1. Reload the Loop projection. It wins over any old Runner-private journal state.
2. Fail closed with `STALE_RUN` if the journal's Run, Contract revision, or plan digest differs from the projection. The message names `agent-loop-runner cleanup TASK` as the way forward.
3. If the journal belongs to a stage or attempt Loop has already moved past, mark it `reconciled_accepted`; that stage is not re-run.
4. If a stage result was persisted but not yet accepted, resubmit that result to Loop instead of invoking the host again; Loop decides whether to accept it.
5. If the process exited before a result was submitted, resume from the recorded completion envelope in the owned workspace; otherwise the current authorized stage is prepared and run.

Process exit status never implies acceptance; only Loop accepts a stage result.

**Known limit: Runner killed while the provider process was running.** If the Runner process itself is killed (for example `kill -9`) after it recorded `process_started` and before it recorded the process exit, the journal keeps `process_started` with no exit evidence. The provider process is not stopped with the Runner and may still finish its edits in the worktree. In that state `run` and `resume` fail closed with `PROCESS_FAILED: incomplete prior process observation requires operator evidence`; `cancel` works only while the recorded process identity (pid and fingerprint) is still alive and otherwise fails with `owned process identity is stale`; `cleanup` fails with `STALE_WORKSPACE` because the evidence is unreconciled. No stage is re-run and Loop's projection is unchanged, but Runner currently offers no command that supplies the missing evidence. Tracked in agent-loop-runner#45.
