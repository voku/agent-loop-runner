# agent-loop-runner

External execution runner for `voku/agent-loop`.

## Architectural role

`agent-loop-runner` is an **optional process supervisor** for external coding agents. It consumes already-authorized stages through `voku/agent-loop`'s public execution API, invokes local agent binaries in isolated Git worktrees, and returns candidate changes and artifact observations for owner validation.

```text
agent-loop-runner (optional execution process plane)
      |
      | public typed Execution API only
      v
agent-loop (authoritative governance root)
```

## Boundaries

1. `voku/agent-loop` never requires `agent-loop-runner`.
2. `agent-loop-runner` depends on `agent-loop`; `agent-loop` never depends on `agent-loop-runner`.
3. Runner never owns approval, Contract mutation, lifecycle truth, validation truth, review verdicts, Learning, or close.
4. Provider processes are bounded: they receive only the stage prompt and allowed environment variables; credentials and arbitrary host state remain outside the prompt boundary.

## Layout

Runner-private state is isolated in `.agent-loop-runner/`:

```text
.agent-loop-runner/
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
- OpenCode: `opencode run <prompt>`;
- Antigravity: `agy --dangerously-skip-permissions -p <prompt>`.

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
