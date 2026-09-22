## Goal

Resolve `EXTERNAL-L1-ISSUE-533` at base commit `a664ca7f545cc83fee36e21ff8094c3cfa82fdfe` by proving or implementing the smallest boundary that lets a runtime-bound consumer execute every owner-produced `command` and `command_template` continuation without parsing `next_action`, reconstructing lifecycle ordering, or moving project/runtime policy into lifecycle semantics. Prefer proof over product code if the current package surfaces already satisfy the acceptance case, and preserve direct-CLI consumers unchanged.

## Context

Work from the approved governed task whose Contract revision is `1`, whose Recall bundle is `975066aa6ca7f34873fcef41fe26c11becf21d786df522e7c119ad2535ba2eff`, and whose execution contract is currently missing. Re-ground the current repository anchors before editing; the execution contract is execution guidance, not new workflow authority.

The approved mutation scope is exactly:

- `src/Run/RunPolicyEvaluation.php`
- `src/Workflow/WorkflowStatusCommand.php`
- `src/Workflow/HostFrontDoorCommand.php`
- `resources/make/agent-loop.mk`
- `tests/CanonicalActionKindTest.php`
- `tests/InitDoctorCommandTest.php`

`RunPolicyEvaluation` defines the host treatment kinds `command`, `command_template`, `host_work`, `decision_required`, and `none`. Lifecycle guidance requires a host to obey `next_action_kind` / `next_action`; `command` is executed as written, while `command_template` is completed from task intent and repository evidence and then executed. The host must not infer lifecycle ordering from command text.

`resources/make/agent-loop.mk` already separates runtime execution from package-owned argv shaping through `AGENT_LOOP_RUN(command, target, user)`. Its default executes the supplied command directly, while a host may override the function to enter a container, bootstrap an application, or select a user. The package already owns workflow targets including plan, approve, enter, finish, contract, execution-profile, attention, status, context, report, review, handoff, and close.

`tests/CanonicalActionKindTest.php` already exercises representative lifecycle results: model-owned plan and execution-contract `command_template`s, human `decision_required` approval, delegated review and Learning `command_template`s, executable `command` enter, and terminal `none`. `tests/InitDoctorCommandTest.php` already proves that a host-defined `AGENT_LOOP_RUN` is accepted without redeclaring package-owned Make targets.

Use those anchors to test the unresolved boundary directly: determine whether a runtime-bound host can take the owner-produced continuation and execute it through the package runtime boundary without parsing or rewriting `next_action`, without maintaining a consumer wrapper per lifecycle action, and without copying lifecycle rules. If the existing surface already provides that path, prove it with focused tests and avoid product changes. If it does not, implement only the smallest package-owned boundary needed to make that path executable.

## Constraints

- Do not modify files outside the six approved scope paths.
- Do not move Docker or other project-specific runtime semantics into `RunPolicyEvaluator` or any lifecycle owner.
- Do not introduce an arbitrary shell-prefix hook, command DSL, `WorkflowManager`, `UniversalWorkflowAdapter`, or Docker orchestration framework.
- Do not broaden the separate Make setup-convergence work.
- Keep lifecycle ownership of *which* canonical action is next separate from project/runtime ownership of *how* the agent-loop process is entered.
- Do not make the host reconstruct lifecycle ordering, parse `next_action` to select a wrapper, or maintain one consumer wrapper per lifecycle action.
- Preserve the default direct execution path for direct-CLI consumers.
- Treat task acceptance criteria as requirements to prove, not as existing evidence.
- Label material conclusions as `VERIFIED`, `INFERRED`, `ASSUMED`, `BLOCKED`, or `CONTRADICTED`; do not claim verification from unexecuted commands or prompt text.
- Prefer a proof-only change when the current API is sufficient. Otherwise keep the implementation to the minimum boundary required by the demonstrated runtime-bound case.

## Verification

Run exactly:

```bash
vendor/bin/phpunit tests/CanonicalActionKindTest.php tests/InitDoctorCommandTest.php
```

```bash
composer ci
```

In the focused proof, exercise a representative runtime-bound `AGENT_LOOP_RUN` override and demonstrate the owner-to-runtime path for both `command` and `command_template` continuations. Cover the ordinary lifecycle through enter, host work, finish, closeout continuations, and completion far enough to prove that the host does not parse `next_action` or select one wrapper per lifecycle action. Also prove that the default direct-CLI path remains unchanged.

Perform a changed-file scope check and fail the slice if any changed file is outside the six approved paths. Do not invent a passing result for runtime-bound behavior that the tests do not actually execute.

## Done When

The result is complete only when observed evidence shows all of the following:

- a representative runtime-bound consumer can enter the governed lifecycle and execute owner-produced `command` and completed `command_template` continuations through the project runtime boundary;
- the consumer performs host work, returns through `finish`, obeys closeout continuations, and reaches completion without parsing `next_action`, reproducing lifecycle ordering, or maintaining one consumer wrapper per lifecycle action;
- lifecycle semantics still own which canonical action is next, while project/runtime integration owns only how the process is entered;
- direct-CLI consumers retain the existing direct execution behavior;
- no prohibited generic command DSL, arbitrary shell-prefix policy, Docker semantics in lifecycle code, or unrelated Make-convergence work is introduced;
- every changed file is inside the approved six-file scope;
- both required validation commands pass.

If the approved scope or available repository evidence cannot demonstrate the required runtime-bound path safely, stop with the result `BLOCKED` and name the exact missing owner-backed evidence or boundary instead of widening scope or guessing.
