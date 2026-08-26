# AGENTS.md

## Repository role

`voku/agent-loop-runner` is the optional execution plane for `voku/agent-loop`. It executes already-authorized governed stages through host adapters, isolated workspaces, process supervision, restart reconciliation, and Runner-private observations.

It does not own approval, Contract/Run truth, workflow state, validation truth, review verdicts, Learning, or close-out. Those remain with `agent-loop` and its semantic owners.

## Dependency direction

Runner depends on `voku/agent-loop`. The inverse dependency is forbidden.

- Do not move host/process concerns into `agent-loop` merely to make Runner simpler.
- Do not copy Loop lifecycle rules into Runner. Reload Loop's typed execution projection before deciding whether work may run.
- Non-CLI hosts should use typed Runner/Loop application APIs rather than parse CLI JSON or stdout.
- Provider binaries, model choice, effort/reasoning settings, credentials, and host trust policy are runtime/environment concerns, not workflow authority.

## Invariants to preserve

- Process exit status, PID, stdout/stderr, host identity, workspace identity, and journal state are observations only.
- Only `agent-loop` may accept a stage result into governed workflow state.
- Stale Run, Contract, execution-plan, stage, attempt, candidate, or workspace bindings fail closed.
- One governed Run gets one isolated Git worktree; the user's active checkout is never the fallback mutation target.
- One Runner process executes a task at a time; mutating work also requires the owned workspace lease.
- `run` and `resume` share reconciliation semantics. Restart recovery must re-read authority rather than trusting old Runner-private state.
- No push, PR merge, permission-bypass flag, or host-policy relaxation is performed by default.
- Runner-private `.agent-loop-runner/` state must remain unknown/non-authoritative to `agent-loop`.

## Implementation guidance

Keep adapters thin around explicit process invocation and bounded observations. New workflow decisions belong in Loop; new host/runtime observations belong here. When a required Loop capability is missing, add a typed owner API in `agent-loop`, release it, then consume the stable version instead of duplicating policy locally.

Avoid giant environment dumps. Execution-environment observations must remain bounded, explicit, and untrusted; credentials, arbitrary environment variables, provider policy, and unrelated host state stay outside the prompt/authority boundary.

## Validation

Run:

```bash
composer ci
```

For dependency/execution-boundary changes, also preserve the installed-consumer proof so Runner is exercised against released production dependencies rather than an accidental local path shadow.

## Releases

Releases are marker-driven. A `.release/<version>.json` marker must point at a release-ready ancestor commit whose own `CHANGELOG.md` contains that release section. Existing tags are immutable.