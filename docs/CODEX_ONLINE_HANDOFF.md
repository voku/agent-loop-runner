# Codex Online handoff: finish production-ready agent-loop-runner

This is the continuation point for a fresh **single-repository, single-PR Codex Online VM session** on `voku/agent-loop-runner` PR #4.

Repository and released-package facts at execution time win over this document. Re-ground before editing.

## Hard prerequisite

Do not begin dependent integration work until the hardened public `voku/agent-loop` execution contract is actually released.

The upstream canonical line is:

- `voku/agent-loop` issue #277;
- canonical PR #280;
- expected public development line `0.18.x`.

The old Runner dependency on `dev-main` is temporary only. Once the exact upstream tag exists, replace it with the compatible stable constraint and regenerate Composer state from a clean resolution.

If the expected release does not exist or does not contain the typed candidate/artifact observation API, report the exact package/API mismatch. Do **not** recreate Loop internals or scrape `.agent-loop/**` state to work around it.

## Fixed architecture

```text
agent-loop-runner
      |
      v
  agent-loop
```

Never the reverse.

`agent-loop` remains authoritative for:

- Task and Contract identity;
- Contract revision;
- approval;
- governed Run;
- resolved versioned ExecutionPlan;
- role semantics and legal transitions;
- owner candidate/artifact/validation evidence;
- Attention authority;
- review / Learning / verify / close truth;
- whether a StageResult may advance execution.

Runner owns only execution-plane observations:

- configured host discovery;
- child process lifecycle;
- cwd/environment projection;
- isolated Run worktree lifecycle;
- runtime locks/journals;
- stdout/stderr diagnostics;
- timeout/cancellation;
- restart/reconciliation;
- Git-native candidate observation;
- narrow artifact observation;
- submission of observations/results through typed public Loop APIs;
- scheduling only the next already-authorized stage.

Runner may execute work. It may never declare itself correct.

These remain non-authoritative:

```text
process exit 0
model says done
model says tests pass
completion JSON exists
model-provided candidate identity
model-provided artifact reference by itself
model-provided validation reference
Runner log path
Runner journal state
```

Runner-private state remains private:

```text
.agent-loop-runner/**
```

Do not teach Loop those paths.

## Current PR

Canonical Runner continuation:

```text
PR #4
branch: fix/production-execution-contract
```

It is a continuation of merged PR #3. Obsolete draft PR #2 is closed.

Do not reopen or port #2 wholesale.

## Already implemented on PR #4

Verify, do not blindly rewrite:

- private-index Git tree candidate observation instead of free-form worktree digest strings;
- exact governed base `HEAD` requirement;
- no commit/ref/push solely to obtain candidate identity;
- typed gateway port for candidate and artifact observations;
- rejection of model-provided validation authority;
- workspace-relative artifact observation with traversal/symlink/missing-file fail-closed behavior;
- normalized bounded completion envelope persisted for restart;
- diagnostic logs remain non-authoritative;
- post-process candidate/evidence/result restart checkpoints;
- exact existing-worktree reopening after post-process crashes;
- same-Task/Run execution lock;
- expanded runtime journal transition and cancellation handling;
- real-Git integration coverage for tracked changes, deletes, executable mode, binary files, untracked files, unusual names, symlinks where available, large files, deterministic identity and source/index isolation;
- real Loop + real Runner coordinator + real temporary Git repository + fake host E2E fixture;
- expanded crash/restart matrix.

At the time this handoff was refreshed, PR #4 still depended on upstream `dev-main` and therefore was intentionally not production-ready.

## Candidate observation contract

The model does not choose candidate identity.

For a mutating stage Runner should:

```text
exact governed Run worktree
  -> keep HEAD at exact governed base
  -> create private temporary Git index
  -> read-tree <base>
  -> git add -A through GIT_INDEX_FILE
  -> git write-tree
  -> repeat/verify stable snapshot where needed
  -> candidate = git-tree-v1:<exact-base>:<tree-object>
  -> submit typed StageCandidateObservation
  -> Loop validates current Task/Run/Contract/plan/stage/attempt/previous-candidate binding
  -> Loop creates owner candidate evidence
```

The private Git index must not replace or mutate either the source checkout index or the linked Run-worktree index.

No commit is required.
No ref is required.
No push is allowed.

Read-only stages must preserve the current candidate exactly. Workspace mutation in a read-only stage is `STALE_WORKSPACE` and evidence must be preserved for diagnosis.

The candidate mechanism must deliberately cover Git semantics rather than manually approximating them:

- tracked modifications;
- binary changes;
- deletes;
- renames as represented by the resulting tree;
- executable mode changes;
- untracked files;
- unusual/NUL-safe filenames through Git argv/index semantics;
- symlinks where the platform supports them;
- large files without custom unsafe whole-repository buffering.

Same workspace state must produce the same candidate. Changed state must produce a different candidate. Restart after candidate observation must reproduce the same identity or fail closed.

## Artifact and validation authority

Model completion output may request artifact paths, but those strings are not evidence.

Runner may interpret an allowed artifact request only as a candidate workspace-relative regular-file observation, verify the actual path/bytes in the Run worktree, hash actual content, and submit a typed `StageArtifactObservation`.

Loop then validates the exact current execution/candidate binding and creates the owner evidence reference.

Invented references must fail closed.

Validation is stricter:

- do not forward model claims as validation authority;
- do not turn Runner logs into validation evidence;
- external Runner code must not mint Loop validation truth;
- deterministic Loop-owned validation/verify is authoritative.

## Attention

Required flow:

```text
agent stage -> NEEDS_CLARIFICATION
  -> Loop creates Attention
  -> Runner reports WAITING_FOR_ATTENTION and stops
  -> human/owner workflow resolves Attention
  -> Loop records authoritative resolution
  -> fresh projection exposes a new authorized attempt
  -> Runner may resume
```

Runner must not resolve human-owned Attention merely because it knows the id.

## Restart invariant

On every `run` and `resume`:

```text
load Runner observation
-> fetch fresh Loop ExecutionProjection
-> Loop wins every disagreement
-> reconcile exact Task/Run/Contract/plan/stage/attempt
-> resume only an unambiguously authorized action
```

Required crash boundaries:

```text
A before process start
B after process start
C after process exit
D after candidate observation
E after artifact/evidence registration
F after StageResult persistence
G after Loop accepts StageResult
H before Runner records accepted reconciliation
```

Critical invariant:

```text
G -> H restart MUST NOT execute the host again
```

A process-started journal without proven terminal process evidence is not permission to execute a second host process. Fail closed unless reconciliation proves the old process outcome.

A post-process restart must not call ordinary workspace acquisition if that path would require the old authoritative candidate to equal an already legitimately changed workspace. Reopen only the exact existing Run worktree and validate its identity/base/candidate checkpoint.

Persist normalized bounded protocol data required for replay. Do not replay authority from potentially truncated diagnostic logs.

## Cancellation/concurrency invariant

At most one Runner reconciliation owner exists per Task/Run.

Read-only stages participate in Run serialization too.

Cancellation must:

- act only on an exact current `ProcessStarted` observation;
- verify process identity/fingerprint to prevent PID-reuse kills;
- verify process-group ownership before negative-PID signalling;
- serialize the cancellation journal transition against coordinator writes;
- never mark the workflow stage complete;
- never allow a stale same-attempt coordinator write to overwrite a recorded cancellation;
- fail closed on cancel/natural-exit ambiguity.

Review timeout termination for the same process-group safety invariant. Do not assume `setsid` succeeded merely because a negative PID exists.

## Completion protocol and host safety

Retain/falsify the strict parser guarantees:

- exactly one completion marker;
- marker is the final non-empty stdout line;
- one JSON object;
- duplicate JSON members rejected;
- only exact expected fields;
- legal outcome only;
- bounded summary;
- bounded reference count and reference length;
- valid Unicode behavior;
- hostile-looking prompt/summary remains data;
- large stdout/stderr diagnostics are bounded;
- no shell interpolation for host argv;
- explicit canonical cwd;
- projected allowlisted environment;
- no unsafe auto-approval/yolo flags;
- secret-like environment values are not persisted in journal metadata.

## Real host adapters

Adapters remain thin runtime translation layers:

- Codex;
- Claude Code;
- OpenCode.

For each binary actually available in the VM:

1. record the exact version;
2. inspect current CLI help sufficiently to verify the adapter argv is still valid;
3. run a tiny disposable non-destructive fixture;
4. verify cwd/stdin-or-argv prompt delivery/output/timeout/completion behavior.

If unavailable, record `HOST_UNAVAILABLE` with probe evidence.

Deterministic fake executable tests remain mandatory regardless of provider availability.

Do not weaken CI merely because a provider binary is missing.

## Required VM workflow

### 1. Re-ground

```bash
git status --short --branch
git log --oneline --decorate -20
git fetch --all --tags --prune
php -v
composer --version
git --version
```

Inspect the actual PR head, `main`, issue #1, installed/tagged `voku/agent-loop` version, Composer constraints and CI state.

### 2. Prove the upstream prerequisite

From a clean Composer resolution, prove that the exact released Loop package provides the candidate/artifact observation API required by this PR.

Do not use a sibling checkout or path repository to fake this proof.

Then replace `dev-main` with the appropriate stable constraint and update `composer.lock`.

Expected shape may be `^0.18.0`, but repository/package evidence wins.

### 3. Establish baseline

Run:

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```

Classify failures:

```text
PRE_EXISTING
INTRODUCED
UNKNOWN_ORIGIN
```

### 4. Finish/falsify implementation

Do not stop after making tests compile against the new API.

Attempt to break:

- stale candidate replay after `CHANGES_REQUIRED`;
- wrong base/current candidate lineage;
- candidate TOCTOU between observation/evidence/submission;
- artifact traversal/symlink escape;
- invented model artifact/validation refs;
- crash after every checkpoint A-H;
- G->H host duplication;
- concurrent same-Run invocation;
- cancel vs natural exit;
- PID reuse;
- journal write/cancel races;
- workspace alias/path escape;
- source checkout/index mutation;
- hidden Git commit/ref creation;
- unbounded logs/protocol fields;
- secret persistence;
- host command injection;
- stale local runtime overriding fresh Loop projection.

Add focused regression tests for real defects.

### 5. Real profile E2E

Using real temporary Git repositories and deterministic fake executable hosts, prove the public typed boundary end-to-end for:

```text
surgical
standard
hardened
```

At least one suite must contain all of:

```text
real released agent-loop governance
+ real agent-loop-runner coordinator
+ real temporary Git repository/worktree
+ fake external executable process
```

Exercise:

- PASS;
- CHANGES_REQUIRED;
- NEEDS_CLARIFICATION;
- resume;
- deterministic verify;
- read-only workspace enforcement;
- actual mutating candidate observation;
- actual artifact observation and owner reference acceptance.

Do not unit-test the coordinator by bypassing the real typed gateway for the only integration proof.

### 6. Installed consumer

Create a clean temporary Composer consumer using tagged packages only.

Prove:

- `agent-loop` works without Runner installed;
- Runner installs with the released Loop version;
- dependency direction is Runner -> Loop only;
- installed `vendor/bin/agent-loop-runner` autoloads and runs;
- no repository-root assumptions;
- no sibling checkout assumptions;
- supported PHP versions remain green.

### 7. Real governed dogfood task

Required before DONE.

Select one real small safe `agent-loop`-style issue/task, or create the smallest justified dogfood task in this repository if no suitable task exists.

Use the authoritative Loop lifecycle:

```text
PLAN
-> APPROVE
-> execution profile
-> ENTER
-> Runner agent stages
-> deterministic validation
-> review
-> Learning
-> verify/close
```

Capture exact Task, Run, plan digest, stage attempts, worktree/candidate evidence, owner evidence, validation, review and close state.

Runner executing a process is not sufficient. Loop must legitimately reach completed workflow state.

## Exact-head merge gates

Before making PR #4 ready or merging:

- stable released Loop constraint, no production `dev-main` leakage;
- `composer validate --strict` green;
- PHPUnit green;
- PHPStan max green;
- PHP 8.3/8.4/8.5 CI green where supported;
- real-Git integration tests green;
- restart/concurrency/cancel tests green;
- surgical/standard/hardened E2E green;
- installed-consumer green;
- provider smoke results recorded honestly;
- real governed dogfood task completed;
- no unresolved review blocker;
- independent blind-spot pass clean.

Earlier green commits do not count after the head moves.

## Explicit non-goals

Do not add merely because context is warm:

- general DAG engine;
- parallel mutating agents;
- automatic model selection;
- automatic profile selection;
- tmux architecture;
- dashboard;
- remote worker fleet;
- Kubernetes/Docker orchestration framework;
- generic workflow language;
- token-price optimizer;
- auto-merge engine;
- Runner Git push support;
- transcript-derived workflow truth;
- new memory/session/kanban system;
- generic typed blocker DAG.

## Blind-spot pass

After normal tests are green, assume the implementation is still wrong.

Explicitly attempt to falsify:

- semantic owner boundary;
- model output becoming authority indirectly;
- forged/stale candidate identity;
- stale owner evidence replay;
- post-observation TOCTOU;
- restart duplicate execution;
- cancellation/PID/process-group safety;
- source/index mutation;
- workspace/symlink escape;
- Git object/ref side effects;
- command injection;
- secret persistence;
- installed-consumer autoload assumptions;
- dev-main leakage;
- CI claims from stale SHA.

## Completion

Do not stop at implementation prose.

`DONE` requires the exact production evidence above, including installed-consumer and one real governed dogfood task.

A genuine unavailable optional provider is not a global blocker; record it and continue independent work.

A genuine upstream/repository/admin prerequisite blocks only dependent work.

## Final report

Return:

```text
STATUS: DONE | BLOCKED

REPOSITORY STATE
- starting SHA
- final SHA
- branch
- clean/dirty

UPSTREAM CONTRACT
- exact agent-loop version/tag installed
- public API proof

IMPLEMENTATION
- concrete behavior

AUTHORITY PROOF
- candidate
- artifact
- validation
- Attention

RESTART / CONCURRENCY
- A-H results
- G->H no-rerun proof
- lock/cancel/PID evidence

PROFILE E2E
- surgical
- standard
- hardened

HOST SMOKES
- Codex
- Claude
- OpenCode

INSTALLED CONSUMER
- exact versions and commands

REAL DOGFOOD TASK
- exact Task/Run/lifecycle evidence

VALIDATION / CI
- exact commands, final head, workflow ids

REMAINING BLOCKERS
- genuine external blockers only

BLIND-SPOT FINDINGS
- defects found after the implementation initially looked ready
```

Work autonomously. Use the real VM and executable evidence. Do not ask for ordinary intermediate approval.