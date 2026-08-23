# Architecture

`agent-loop-runner` is an optional execution plane. Its only governance dependency is the public typed `voku\AgentLoop\Execution\ExecutionGateway`; it never reads `.agent-loop/**` directly. `ExecutionCoordinator` is the single path behind both `run` and `resume`.

Each iteration fetches a fresh authoritative projection. Agent stages receive only a `StageExecutionBundle`, use one detached Run worktree, and submit a typed `StageResult`. Deterministic `verify` remains inside `agent-loop`. Runner state records process and submission observations, not workflow truth.

A stable submission ID is persisted before host start. The exact result is persisted before submission. If authority has advanced after a crash, the host is not rerun; if the same attempt remains current, the persisted result is resubmitted unchanged.
