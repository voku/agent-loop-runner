# Host adapters

Adapters are deliberately thin and noninteractive:

* Codex: `codex exec --ephemeral -`, prompt on stdin.
* Claude Code: `claude -p <prompt>`.
* OpenCode: `opencode run <prompt>`.

Binary discovery and `--version` probes are observations. No model, effort, approval bypass, or workflow outcome is selected by an adapter. Deterministic fake adapters cover orchestration in CI; real provider smoke tests are run only when binaries and credentials are available.
