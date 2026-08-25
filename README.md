# agent-loop-runner

Optional execution plane for [`voku/agent-loop`](https://github.com/voku/agent-loop).

`agent-loop-runner` executes already-authorized governed stages through host adapters, isolated Git workspaces, and restart-safe process supervision. It does **not** own approval, Contract mutation, lifecycle truth, validation truth, review verdicts, Learning, or close.

```text
agent-loop-runner
      |
      v
  agent-loop
```

The inverse dependency is forbidden. `agent-loop` remains fully usable without this package installed and continues not to invoke an LLM itself.

The implementation roadmap and definition of done are tracked in [issue #1](https://github.com/voku/agent-loop-runner/issues/1). The prerequisite typed execution protocol is tracked in [`voku/agent-loop#269`](https://github.com/voku/agent-loop/issues/269).

## Commands

```text
agent-loop-runner doctor
agent-loop-runner status <task-id>
agent-loop-runner run <task-id>
agent-loop-runner resume <task-id>
agent-loop-runner cancel <task-id>
agent-loop-runner cleanup <task-id>
```

`run` and `resume` use the same reconciliation path. A nonblocking per-task execution lock prevents concurrent Runner processes from executing the same governed stage, and every iteration reloads `agent-loop`'s authoritative execution projection before deciding whether a stage may run.

## Typed application API

Non-CLI adapters should use `voku\AgentLoopRunner\Application\RunnerControlService` rather than parse CLI JSON or reconstruct the Runner object graph. It exposes `status()`, `run()`, `resume()`, `cancel()`, and `cleanup()`.

`status()` returns a `RunnerStatus` with two deliberately separate values:

- `authority`: the current `agent-loop` `ExecutionProjection`;
- `observation`: the optional Runner-private `RuntimeAttempt`.

A process exit, PID, host ID, workspace identity, or journal status therefore stays observation. Only `agent-loop` remains execution/workflow authority.

## State

Runner-private state lives under `.agent-loop-runner/` in the target project:

```text
config.json
runtime/
worktrees/
logs/
```

Those paths are runner observations/configuration only. They are never workflow authority and are deliberately unknown to `agent-loop`.

## Host defaults

The built-in adapters use the documented non-interactive entry points without permission-bypass flags:

- Codex: `codex exec --ephemeral -` with the bounded stage prompt on stdin;
- Claude Code: `claude -p <prompt>`;
- OpenCode: `opencode run <prompt>`.

Binary paths can be configured explicitly. Model choice, reasoning/effort settings, provider credentials, and host trust policy stay outside this package's workflow semantics.

## Safety invariants

- process exit `0` is only a runtime observation;
- stdout/stderr never becomes workflow truth;
- only `agent-loop` accepts a `StageResult` transition;
- one governed Run gets one isolated Git worktree;
- only one Runner process executes a task at a time; mutating stages additionally own an exclusive workspace lease;
- the user's active checkout is never the fallback mutation target;
- no push, PR merge, or permission-bypass flag is performed by default;
- stale Run, Contract, execution-plan, attempt, or workspace bindings fail closed;
- restart reconciliation is designed for normal process failure/restart. Sudden power-loss durability of a journal rename is not claimed on platforms where PHP cannot synchronize the parent directory entry.

## Design documentation

- [Architecture](docs/architecture.md)
- [Execution and restart model](docs/execution-model.md)
- [Host adapters](docs/host-adapters.md)
- [Security boundaries](docs/security.md)

## Status

The package includes the typed gateway coordinator, restart journal, isolated Run workspaces, CLI, and deterministic profile fixtures. Provider availability remains an environment-specific runtime fact.
