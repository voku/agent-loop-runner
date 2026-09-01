# Host adapters

Adapters are deliberately thin and noninteractive:

* Codex: `codex exec --ephemeral -`, prompt on stdin.
* Claude Code: `claude -p <prompt>`.
* OpenCode: `opencode run <prompt>`.
* Antigravity: `agy --dangerously-skip-permissions -p <prompt>`.

Binary discovery and `--version` probes are observations. No model, effort, approval bypass, or workflow outcome is selected by an adapter. Deterministic fake adapters cover orchestration in CI; real provider smoke tests are run only when binaries and credentials are available.

## Host trust for mutating stages

Adapters intentionally expose no permission, approval, or sandbox flag. Host trust is an
environment concern, so a mutating governed stage only works when the operator has already
granted the provider process permission to write, by either:

* the provider's own configuration (for Claude Code, a project `.claude/settings.json`
  with `permissions.defaultMode` set to a write-capable mode such as `acceptEdits`), or
* pointing `hosts.<id>.binary` in `.agent-loop-runner/config.json` at an operator-owned
  launcher script that adds the trust flags before exec'ing the real CLI.

For example, the Claude Code project setting is nested under `permissions`:

```json
{
  "permissions": {
    "defaultMode": "acceptEdits"
  }
}
```

`binary` accepts an absolute path, so the launcher route needs no Runner change. Runner must
not grow its own permission knob: that would move host trust policy inside the execution plane.

Observed with real Claude Code 2.1.x: with default permissions a write is denied, yet the
process still **exits 0** and prints an explanatory sentence instead of performing the work.
A mutating stage run that way produces an unchanged candidate. This is a concrete instance of
the standing invariant — `process exit 0 != stage passed`. A zero process exit is necessary on
the current execution path but insufficient for acceptance: non-zero exits are rejected first,
and a successful process still needs a parsed completion envelope plus owner-side validation.
