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

**`WAITING_FOR_ATTENTION`.** When the freshly loaded `ExecutionProjection` carries an Attention, `run` and `resume` stop before any host invocation. They write `WAITING_FOR_ATTENTION: <attention-id>` to stderr and exit with code `16` (`ExitCode::WAITING_FOR_ATTENTION`). When an Attention is already open, Runner starts no process. The check runs once per reconciliation iteration, before any host invocation, and nothing interrupts a provider process that is already running: if an Attention is raised during an active invocation, that invocation finishes, Runner then attempts to submit its result, and Loop rejects a new submission while an Attention is open (`Execution is waiting for Attention <id>`). The next `run`/`resume` stops at the check above.

**Where the id is visible.** The Attention id is in the stderr message above and in `agent-loop-runner status TASK` as `authority.attention_id` (`null` when there is no Attention). Both come from the Loop projection.

**Who resolves it.** Attention is owned and resolved by `agent-loop` (`workflow attention --resolve`). Runner never resolves, clears, or works around an Attention; its journal label `waiting_for_attention` is only an observation. After Loop resolves the Attention, run `resume TASK` (or `run TASK`).

**What `run`/`resume` do after an interruption.** Both take the per-task execution lock and enter the same reconciliation. It handles an Attention stop and a Runner restart after the provider process has exited:

1. Reload the Loop projection. It wins over any old Runner-private journal state.
2. Fail closed with `STALE_RUN` if the journal's Run, Contract revision, or plan digest differs from the projection. The message names `agent-loop-runner cleanup TASK` as the way forward.
3. If the journal belongs to a stage or attempt Loop has already moved past, mark it `reconciled_accepted`; that stage is not re-run.
4. If a stage result was persisted but not yet accepted, resubmit that result to Loop instead of invoking the host again; Loop decides whether to accept it.
5. If the process exited before a result was submitted and its completion envelope was recorded, resume from that envelope in the owned workspace. The stage is prepared and run only when there is no prior attempt or the prior attempt is still `prepared`; any other incomplete observation fails closed (see below).

Process exit status never implies acceptance; only Loop accepts a stage result.

**Runner killed while the provider process was running.** If the Runner process itself is killed (for example `kill -9`) after it recorded `process_started` and before it recorded the process exit, the journal keeps `process_started` with no exit evidence, and the provider process is not stopped with the Runner. `run` and `resume` then fail closed with `PROCESS_FAILED: incomplete prior process observation requires operator evidence`, and `cancel` fails with `owned process identity is stale` once the process is gone. No stage is re-run and Loop's projection is unchanged. The operator exit is `abandon` followed by `cleanup`; see [Abandoning a stale attempt](#abandoning-a-stale-attempt).

## Abandoning a stale attempt

If the Runner itself is killed (for example `kill -9`) after it recorded `process_started` and before it recorded the process exit, the journal holds an attempt with no exit evidence. `run` and `resume` then fail closed with `PROCESS_FAILED: incomplete prior process observation requires operator evidence`, and `cancel` fails with `owned process identity is stale` because there is no live process left to signal.

`agent-loop-runner abandon TASK --by <actor> --reason <text>` is the explicit operator step that supplies that evidence. It takes the per-task execution lock and then requires all of the following, otherwise it fails closed with `PROCESS_FAILED` and leaves the journal byte-identical:

- the attempt is `process_started` and unchanged since it was read;
- a non-empty actor (at most 200 bytes) and a reason (at most 1000 bytes) are given; Runner never inspects whether the actor looks human;
- the recorded process identity is **provably gone**: the pid no longer exists, or it now belongs to a process with a different fingerprint (pid reuse), **and** nothing is left in the provider's process group (the provider runs as its own group leader, so a child can outlive it; a group with live members is refused and must be stopped first, because `cancel` only works while the leader is alive). A process that is still running is refused with a pointer to `cancel`; an identity that cannot be inspected on this platform, or a record without pid and fingerprint, is refused because gone-ness cannot be proven.

On success the attempt keeps its Run, Contract revision, plan digest, stage, attempt and submission id, becomes `cancelled` with no stage result and no completion envelope, and its process evidence gains `abandoned_by`, `abandoned_reason` and `abandoned_at`. `abandon` never starts a provider, submits a result or talks to `agent-loop`; Loop's projection stays authoritative and unchanged.

`abandon` is not the last step. `cleanup` then retires the record and the worktree **only if the worktree is clean**. A provider that outlived the Runner may have left edits behind; `cleanup` keeps refusing a dirty worktree (`Refusing to remove dirty Run workspace`), so inspect it (`git -C <worktree> status` and `diff`), keep or discard the changes yourself, then run `cleanup`. After `cleanup` the stage can be run again from a fresh attempt, with Loop's projection deciding which stage that is.

## The `controls` projection is a snapshot

`agent-loop-runner status TASK` reports `controls.run`, `controls.resume` and `controls.cancel`. They are read-only projections: `status` never signals a process, writes the journal or resolves anything. `controls.cancel` is `true` only when `cancel` could signal the recorded provider right now, using the same check `cancel` itself performs: the attempt is `process_started`, the recorded pid exists and still has the recorded fingerprint (so a vanished or reused pid does not count), the platform supports process groups, and the pid leads its own process group. Otherwise it is `false`, including when the identity cannot be inspected.

The value is a snapshot, not a guarantee: the process can exit, or the Runner can start or stop, between reading `status` and acting on it, and `cancel` re-checks everything and fails closed. For a process that is already gone, `cancel` is not available; use [`abandon`](#abandoning-a-stale-attempt).

