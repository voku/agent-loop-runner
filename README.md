# agent-loop-runner

External execution runner and process supervisor for [`voku/agent-loop`](https://github.com/voku/agent-loop).

[![Build Status](https://github.com/voku/agent-loop-runner/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/agent-loop-runner/actions)
[![Latest Stable Version](https://poser.pugx.org/voku/agent-loop-runner/v/stable)](https://packagist.org/packages/voku/agent-loop-runner)
[![Total Downloads](https://poser.pugx.org/voku/agent-loop-runner/downloads)](https://packagist.org/packages/voku/agent-loop-runner)
[![Monthly Downloads](https://poser.pugx.org/voku/agent-loop-runner/d/monthly)](https://packagist.org/packages/voku/agent-loop-runner)
[![License](https://poser.pugx.org/voku/agent-loop-runner/license)](https://packagist.org/packages/voku/agent-loop-runner)
[![PHP Version Require](https://poser.pugx.org/voku/agent-loop-runner/require/php)](https://packagist.org/packages/voku/agent-loop-runner)
[![GitHub Stars](https://img.shields.io/github/stars/voku/agent-loop-runner?style=flat-square)](https://github.com/voku/agent-loop-runner/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/voku/agent-loop-runner?style=flat-square)](https://github.com/voku/agent-loop-runner/network/members)

## Architectural role

`agent-loop-runner` is an **optional process supervisor** for external coding agents. It consumes already-authorized stages through `voku/agent-loop`'s public execution API, invokes local agent binaries in isolated Git worktrees, and returns candidate changes and artifact observations for owner validation.

```text
agent-loop-runner (optional execution process plane)
      |
      | public typed Execution API only
      v
agent-loop (authoritative governance root)
```

## Requirements

| Requirement | Version / Specification |
| --- | --- |
| PHP | `^8.3` |
| Git | `^2.25` (worktrees supported) |
| `voku/agent-loop` | `^0.19.0` |
| Coding Host(s) | At least one installed CLI: Codex, Claude Code, OpenCode, or Antigravity (`agy`) |

## Installation

```bash
composer require --dev voku/agent-loop-runner
```

The package exposes the standalone supervisor CLI:

```bash
vendor/bin/agent-loop-runner
```

## Quick Start

```bash
# 1. Diagnose environment, dependencies, and detected host adapters
vendor/bin/agent-loop-runner doctor

# 2. Inspect runner status and authorized stage for a task
vendor/bin/agent-loop-runner status TASK-123

# 3. Execute the authorized stage in an isolated Git worktree
vendor/bin/agent-loop-runner run TASK-123

# 4. Resume an interrupted execution or cancel an active process
vendor/bin/agent-loop-runner resume TASK-123
vendor/bin/agent-loop-runner cancel TASK-123

# 5. Clean up the isolated run workspace once reconciled
vendor/bin/agent-loop-runner cleanup TASK-123
```

## CLI Reference

| Command | Usage | Description |
| --- | --- | --- |
| `doctor` | `agent-loop-runner doctor` | Diagnose environment, Git worktree capability, and reachability of configured coding hosts. |
| `status` | `agent-loop-runner status TASK` | Inspect execution status, active Run bindings, workspace state, and stage attempts. |
| `run` | `agent-loop-runner run TASK` | Execute only the currently authorized external stage in an isolated Git worktree. |
| `resume` | `agent-loop-runner resume TASK` | Reconcile and resume an interrupted authorized external stage. |
| `cancel` | `agent-loop-runner cancel TASK` | Terminate the active agent supervisor process for the specified task. |
| `cleanup` | `agent-loop-runner cleanup TASK` | Remove the reconciled clean runner workspace after stage completion. |

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

## Configuration

Runner configuration is optional. When `.agent-loop-runner/config.json` is absent, built-in defaults apply.

```json
{
  "schema_version": 1,
  "hosts": {
    "codex": { "binary": "codex" },
    "claude": { "binary": "claude" },
    "opencode": { "binary": "opencode" },
    "agy": { "binary": "agy" }
  },
  "roles": {
    "investigator": "codex",
    "builder": "codex",
    "reviewer": "claude",
    "correctness-review": "claude",
    "architecture-review": "claude",
    "hardening": "codex",
    "independent-verification": "claude",
    "blindspot-review": "claude"
  },
  "execution": {
    "timeout_seconds": 1800,
    "environment_allowlist": [
      "PATH", "HOME", "USER", "LOGNAME", "TMPDIR",
      "OPENAI_API_KEY", "ANTHROPIC_API_KEY", "CLAUDE_CODE_OAUTH_TOKEN",
      "ANTIGRAVITY_LS_ADDRESS", "ANTIGRAVITY_CSRF_TOKEN", "GEMINI_API_KEY"
    ]
  }
}
```

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
