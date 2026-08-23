# Security boundaries

* Processes receive argv arrays and an explicit canonical worktree cwd; no shell command is assembled.
* Only configured environment variable names are projected. Environment values and provider credentials are not written to the runtime journal.
* Provider output is diagnostic evidence. Only the exact final completion envelope is parsed into candidate data, and only `agent-loop` can accept it.
* Run workspaces are verified against the repository common directory and exact base commit. Dirty evidence is preserved and cleanup fails closed.
* Mutating leases use a nonblocking exclusive lock. Cancellation requires both an owned PID and its Linux process-start fingerprint to reduce PID-reuse races.
* The runner has no push, merge, automatic commit, permission bypass, or remote-mutation operation.

Stdout and stderr are stored separately with bounded content and hashes of the original streams. Arbitrary provider prose cannot be guaranteed secret-free; operators must not ask providers to print credentials.
