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

## Abandoning a stale attempt

If the Runner itself is killed (for example `kill -9`) after it recorded `process_started` and before it recorded the process exit, the journal holds an attempt with no exit evidence. `run` and `resume` then fail closed with `PROCESS_FAILED: incomplete prior process observation requires operator evidence`, and `cancel` fails with `owned process identity is stale` because there is no live process left to signal.

`agent-loop-runner abandon TASK --by <actor> --reason <text>` is the explicit operator step that supplies that evidence. It takes the per-task execution lock and then requires all of the following, otherwise it fails closed with `PROCESS_FAILED` and leaves the journal byte-identical:

- the attempt is `process_started` and unchanged since it was read;
- a non-empty actor (at most 200 bytes) and a reason (at most 1000 bytes) are given; Runner never inspects whether the actor looks human;
- the recorded process identity is **provably gone**: the pid no longer exists, or it now belongs to a process with a different fingerprint (pid reuse), **and** nothing is left in the provider's process group (the provider runs as its own group leader, so a child can outlive it; a group with live members is refused and must be stopped first, because `cancel` only works while the leader is alive). A process that is still running is refused with a pointer to `cancel`; an identity that cannot be inspected on this platform, or a record without pid and fingerprint, is refused because gone-ness cannot be proven.

On success the attempt keeps its Run, Contract revision, plan digest, stage, attempt and submission id, becomes `cancelled` with no stage result and no completion envelope, and its process evidence gains `abandoned_by`, `abandoned_reason` and `abandoned_at`. `abandon` never starts a provider, submits a result or talks to `agent-loop`; Loop's projection stays authoritative and unchanged.

`abandon` is not the last step. `cleanup` then retires the record and the worktree **only if the worktree is clean**. A provider that outlived the Runner may have left edits behind; `cleanup` keeps refusing a dirty worktree (`Refusing to remove dirty Run workspace`), so inspect it (`git -C <worktree> status` and `diff`), keep or discard the changes yourself, then run `cleanup`. After `cleanup` the stage can be run again from a fresh attempt, with Loop's projection deciding which stage that is.
