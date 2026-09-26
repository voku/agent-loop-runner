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
