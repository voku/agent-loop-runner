# Host adapters

Adapters are deliberately thin and noninteractive:

* Codex: `codex exec --ephemeral -`, prompt on stdin.
* Claude Code: `claude -p <prompt>`.
* OpenCode: `opencode run <prompt>`.
* Antigravity: `agy -p <prompt>`; Runner does not add permission-bypass flags.

Binary discovery and `--version` probes are observations. No model, effort, approval bypass, or workflow outcome is selected by an adapter. Deterministic fake adapters cover orchestration in CI; real provider smoke tests are run only when binaries and credentials are available.

## Capacity probes

Hosts may define an optional `resource_command` in `.agent-loop-runner/config.json`,
for example:

```json
{
  "hosts": {
    "codex": {
      "binary": "codex",
      "resource_command": ["codex-cli-usage", "json"]
    }
  },
  "execution": {
    "quota_usage_threshold": 0.95
  }
}
```

The command is invoked as an argument vector with the projected environment;
Runner does not invoke a shell. Its output is an untrusted observation. The
capacity inspector understands remaining/used percentages, used-versus-total
JSON values, reset timestamps or durations, and common provider usage-limit
messages. Unknown or unparseable output does not claim a quota state.

Keep `.agent-loop-runner/config.json` operator-owned and untracked. Runner
rejects a tracked config because its binaries and capacity commands execute on
the operator's machine and may receive provider credentials.

Before creating a Run worktree, Runner checks the selected host. At or above
the threshold it uses a configured role fallback (or the primary host's
`fallback`) if that host is available and below the threshold. Otherwise it
fails closed with `QUOTA_LIMIT_REACHED`, explaining the reason, reset time when
known, and how to configure a fallback.

## Host trust for mutating stages

The execution authority still comes from `agent-loop`; host permissions do not
approve a Contract or prove a completed stage. Runner does **not** add blanket
permission-bypass flags.

For Claude Code, the current `ClaudeHostAdapter::argv()` has explicit,
stage-specific behavior:

- A read-only stage invokes `claude -p <prompt>` without additional permission
  flags.
- An already-authorized **mutating** stage invokes Claude with
  `--permission-mode acceptEdits`. This enables file edits in the isolated Run
  workspace without requiring a project-wide
  `.claude/settings.json` `permissions.defaultMode` change or an operator
  launcher merely to enable ordinary edits.
- Approved validation commands are converted into exact
  `--allowedTools Bash(<command>)` rules. The adapter does not supply wildcards,
  general Bash permission or persistent user/project settings.

The accepted commands come from the current approved execution request.
`acceptEdits` is not permission to bypass host policies for other tools or
perform unrelated commands. Other provider adapters retain their own documented
host-trust behavior; operators remain responsible for credentials and the
provider runtime. An operator-provided binary/launcher remains a configuration
option, not a Claude Code prerequisite.

A process may exit successfully without producing an accepted candidate.
An exit code of zero is only process evidence; Runner and Loop must still
validate the completion envelope, changed candidate and governed stage result.
