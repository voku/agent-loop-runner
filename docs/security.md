# Security boundaries

* Processes receive argv arrays and an explicit canonical worktree cwd; no shell command is assembled.
* Only configured environment variable names are projected. Environment values and provider credentials are not written to the runtime journal.
* Immediately before a fresh agent process, Runner probes only the host selected for that stage in the isolated worktree and sends `agent-loop` a bounded observation containing the configured host id and its availability/version. Allowlisted environment values are never copied into that observation. Runner rechecks the worktree candidate after probing, and only the environment-bound bundle returned by `agent-loop` supplies the executed prompt. A pre-start resume probes again; durable post-process recovery never re-executes the host merely to refresh environment facts.
* Provider output is diagnostic evidence. Only the exact final completion envelope is parsed into candidate data, and only `agent-loop` can accept it.
* Run workspaces are verified against the repository common directory and exact base commit. Dirty evidence is preserved and cleanup fails closed.
* Each task has a nonblocking Runner execution lock around the complete `run`/`resume` reconciliation path, so concurrent Runner processes cannot execute the same governed stage twice, including read-only stages. Cleanup takes the same lock; cancellation deliberately does not because it must be able to terminate an active owned process.
* Mutating leases additionally use a nonblocking exclusive workspace lock. Cancellation requires both an owned PID and its Linux process-start fingerprint to reduce PID-reuse races, and that fingerprint survives runtime-journal reloads.
* Runtime-journal replacement is atomically visible and file data is flushed/`fsync()`ed where available. PHP has no portable parent-directory `fsync`, so sudden power-loss durability of the rename is explicitly outside the Runner's exactly-once guarantee; the supported guarantee covers normal process failure/restart with fresh upstream reconciliation.
* The runner has no push, merge, automatic commit, permission bypass, or remote-mutation operation.

Stdout and stderr are stored separately with bounded content and hashes of the original streams. Arbitrary provider prose cannot be guaranteed secret-free; operators must not ask providers to print credentials.
