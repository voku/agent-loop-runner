from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"expected one match in {path}, found {count}")
    p.write_text(text.replace(old, new, 1))


# Hardened coordinator: observe only after the exact workspace/host is known,
# then ask Loop to bind that observation into the final prompt.
replace_once(
    "src/Execution/ExecutionCoordinator.php",
    "use voku\\AgentLoop\\Execution\\ExecutionProjection;\n",
    "use voku\\AgentLoop\\Execution\\ExecutionEnvironmentObservation;\nuse voku\\AgentLoop\\Execution\\ExecutionEnvironmentTool;\nuse voku\\AgentLoop\\Execution\\ExecutionProjection;\n",
)
replace_once(
    "src/Execution/ExecutionCoordinator.php",
    """                    $host = $this->hosts[$hostId] ?? null;\n                    if (!$host instanceof HostAdapter) {\n                        throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);\n                    }\n                    $submissionId = $local !== null\n""",
    """                    $host = $this->hosts[$hostId] ?? null;\n                    if (!$host instanceof HostAdapter) {\n                        throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);\n                    }\n\n                    $environment = (new EnvironmentProjector())->project($this->config->environmentAllowlist);\n                    $availability = $host->probe($this->supervisor, $workspace->lease->path, $environment);\n                    if (!$availability->available()) {\n                        throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);\n                    }\n                    $bundle = $this->gateway->prepareStageForEnvironment(\n                        $taskId,\n                        $stageId,\n                        new ExecutionEnvironmentObservation(\n                            $bundle->taskId,\n                            $bundle->runId,\n                            $bundle->contractRevision,\n                            $bundle->executionPlanDigest,\n                            $bundle->stageId,\n                            $bundle->attempt,\n                            $bundle->candidateRevision,\n                            $hostId,\n                            [new ExecutionEnvironmentTool($hostId, true, $availability->version)],\n                        ),\n                    );\n                    if ($bundle->environmentObservationDigest === null) {\n                        throw new RuntimeException('TRANSITION_REJECTED: environment-aware stage bundle is missing observation lineage.');\n                    }\n                    if ($bundle->baseCommit !== $baseCommit || $bundle->roleId !== $roleId) {\n                        throw new RuntimeException('STALE_ENVIRONMENT_OBSERVATION: environment-aware stage identity changed after workspace acquisition.');\n                    }\n                    $this->assertRepositoryRoot($bundle);\n\n                    $submissionId = $local !== null\n""",
)
replace_once(
    "src/Execution/ExecutionCoordinator.php",
    """                            $bundle->prompt,\n                            (new EnvironmentProjector())->project($this->config->environmentAllowlist),\n                            $this->config->timeoutSeconds,\n""",
    """                            $bundle->prompt,\n                            $environment,\n                            $this->config->timeoutSeconds,\n""",
)

# Restart fixture: implement the new owner port and prove re-probe-before-start,
# no environment-secret copying, and unavailable-host fail-closed behavior.
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    "use voku\\AgentLoop\\Execution\\ExecutionProfileName;\n",
    "use voku\\AgentLoop\\Execution\\ExecutionEnvironmentObservation;\nuse voku\\AgentLoop\\Execution\\ExecutionProfileName;\n",
)
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    """    public function testCrashBeforeProcessReusesStableSubmissionAndRunsOnceOnResume(): void\n    {\n        $gateway = new FakeGateway($this->root, $this->base);\n        $host = new MutatingCountingHost();\n        try {\n            $this->coordinator($gateway, $host, new ThrowAtBoundary('before_process_start'))->run('TASK');\n            self::fail('Expected injected crash.');\n        } catch (InjectedCrash) {\n        }\n        $submission = $this->journal->load('TASK')?->submissionId;\n\n        $this->coordinator($gateway, $host, new NullCoordinatorHook())->resume('TASK');\n        self::assertSame(1, $host->executions);\n        self::assertSame($submission, $gateway->lastSubmissionId);\n    }\n""",
    """    public function testCrashBeforeProcessReusesStableSubmissionAndReobservesCurrentEnvironment(): void\n    {\n        $gateway = new FakeGateway($this->root, $this->base);\n        $host = new MutatingCountingHost();\n        try {\n            $this->coordinator($gateway, $host, new ThrowAtBoundary('before_process_start'))->run('TASK');\n            self::fail('Expected injected crash.');\n        } catch (InjectedCrash) {\n        }\n        $submission = $this->journal->load('TASK')?->submissionId;\n        self::assertSame(1, $host->probes);\n        $host->version = '2';\n\n        $this->coordinator($gateway, $host, new NullCoordinatorHook())->resume('TASK');\n        self::assertSame(1, $host->executions);\n        self::assertSame(2, $host->probes);\n        self::assertSame(2, $gateway->environmentPreparations);\n        self::assertSame('2', $gateway->lastEnvironmentObservation?->tools[0]->version);\n        self::assertSame($submission, $gateway->lastSubmissionId);\n    }\n\n    public function testEnvironmentObservationDoesNotCopyAllowlistedSecretValuesIntoPromptFacts(): void\n    {\n        $previous = getenv('OPENAI_API_KEY');\n        putenv('OPENAI_API_KEY=runner-secret-must-not-enter-observation');\n        try {\n            $gateway = new FakeGateway($this->root, $this->base);\n            $host = new MutatingCountingHost();\n            $this->coordinator($gateway, $host, new NullCoordinatorHook())->run('TASK');\n\n            self::assertSame(1, $host->probes);\n            self::assertSame(1, $gateway->environmentPreparations);\n            self::assertNotNull($gateway->lastEnvironmentObservation);\n            self::assertSame('codex', $gateway->lastEnvironmentObservation->hostId);\n            self::assertSame('codex', $gateway->lastEnvironmentObservation->tools[0]->id);\n            self::assertStringNotContainsString(\n                'runner-secret-must-not-enter-observation',\n                json_encode($gateway->lastEnvironmentObservation->toArray(), JSON_THROW_ON_ERROR),\n            );\n            self::assertStringContainsString('environment-bound:', $host->lastPrompt ?? '');\n        } finally {\n            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);\n        }\n    }\n\n    public function testUnavailableSelectedHostFailsBeforeEnvironmentPromptOrExecution(): void\n    {\n        $gateway = new FakeGateway($this->root, $this->base);\n        $host = new MutatingCountingHost(available: false);\n\n        try {\n            $this->coordinator($gateway, $host, new NullCoordinatorHook())->run('TASK');\n            self::fail('Expected unavailable host rejection.');\n        } catch (RuntimeException $exception) {\n            self::assertSame('HOST_UNAVAILABLE: codex', $exception->getMessage());\n        }\n\n        self::assertSame(1, $host->probes);\n        self::assertSame(0, $host->executions);\n        self::assertSame(0, $gateway->environmentPreparations);\n    }\n""",
)
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    """    public int $artifactRegistrations = 0;\n    public ?string $lastSubmissionId = null;\n""",
    """    public int $artifactRegistrations = 0;\n    public int $environmentPreparations = 0;\n    public ?string $lastSubmissionId = null;\n    public ?ExecutionEnvironmentObservation $lastEnvironmentObservation = null;\n""",
)
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    """    public function recordStageCandidate(StageCandidateObservation $observation): string\n""",
    """    public function prepareStageForEnvironment(\n        string $taskId,\n        string $stageId,\n        ExecutionEnvironmentObservation $observation,\n    ): StageExecutionBundle {\n        ++$this->environmentPreparations;\n        $this->lastEnvironmentObservation = $observation;\n        $bundle = $this->prepareStage($taskId, $stageId);\n\n        return new StageExecutionBundle(\n            taskId: $bundle->taskId,\n            runId: $bundle->runId,\n            contractRevision: $bundle->contractRevision,\n            executionPlanDigest: $bundle->executionPlanDigest,\n            stageId: $bundle->stageId,\n            attempt: $bundle->attempt,\n            kind: $bundle->kind,\n            roleId: $bundle->roleId,\n            mayMutate: $bundle->mayMutate,\n            repositoryRoot: $bundle->repositoryRoot,\n            baseCommit: $bundle->baseCommit,\n            candidateRevision: $bundle->candidateRevision,\n            contractSource: $bundle->contractSource,\n            recallSource: $bundle->recallSource,\n            allowedScope: $bundle->allowedScope,\n            requiredValidation: $bundle->requiredValidation,\n            priorHandoff: $bundle->priorHandoff,\n            acceptedOutcomes: $bundle->acceptedOutcomes,\n            completionMarker: $bundle->completionMarker,\n            prompt: 'environment-bound:' . $observation->digest() . "\\n",\n            environmentObservationDigest: $observation->digest(),\n        );\n    }\n\n    public function recordStageCandidate(StageCandidateObservation $observation): string\n""",
)
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    """final class MutatingCountingHost implements HostAdapter\n{\n    public int $executions = 0;\n\n    public function id(): string\n""",
    """final class MutatingCountingHost implements HostAdapter\n{\n    public int $executions = 0;\n    public int $probes = 0;\n    public string $version = '1';\n    public ?string $lastPrompt = null;\n\n    public function __construct(private readonly bool $available = true)\n    {\n    }\n\n    public function id(): string\n""",
)
replace_once(
    "tests/Integration/Execution/ExecutionCoordinatorRestartTest.php",
    """    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability\n    {\n        return new HostAvailability('codex', 'fake', '1', null);\n    }\n\n    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult\n    {\n        ++$this->executions;\n""",
    """    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability\n    {\n        ++$this->probes;\n\n        return $this->available\n            ? new HostAvailability('codex', 'fake', $this->version, null)\n            : new HostAvailability('codex', null, null, 'binary not found');\n    }\n\n    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult\n    {\n        ++$this->executions;\n        $this->lastPrompt = $request->prompt;\n""",
)

# Profile E2E: every agent stage must go through environment finalization.
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    "use voku\\AgentLoop\\Execution\\ExecutionProfileName;\n",
    "use voku\\AgentLoop\\Execution\\ExecutionEnvironmentObservation;\nuse voku\\AgentLoop\\Execution\\ExecutionProfileName;\n",
)
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    """        self::assertSame(count($stages) - 1, $host->executions);\n        self::assertSame(1, $gateway->deterministicExecutions);\n""",
    """        self::assertSame(count($stages) - 1, $host->executions);\n        self::assertSame(count($stages) - 1, $host->probes);\n        self::assertSame(count($stages) - 1, $gateway->environmentPreparations);\n        self::assertSame(1, $gateway->deterministicExecutions);\n""",
)
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    """    private int $index = 0;\n    public int $deterministicExecutions = 0;\n""",
    """    private int $index = 0;\n    public int $deterministicExecutions = 0;\n    public int $environmentPreparations = 0;\n""",
)
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    """    public function recordStageCandidate(StageCandidateObservation $observation): string\n""",
    """    public function prepareStageForEnvironment(\n        string $taskId,\n        string $stageId,\n        ExecutionEnvironmentObservation $observation,\n    ): StageExecutionBundle {\n        ++$this->environmentPreparations;\n        $bundle = $this->prepareStage($taskId, $stageId);\n\n        return new StageExecutionBundle(\n            taskId: $bundle->taskId,\n            runId: $bundle->runId,\n            contractRevision: $bundle->contractRevision,\n            executionPlanDigest: $bundle->executionPlanDigest,\n            stageId: $bundle->stageId,\n            attempt: $bundle->attempt,\n            kind: $bundle->kind,\n            roleId: $bundle->roleId,\n            mayMutate: $bundle->mayMutate,\n            repositoryRoot: $bundle->repositoryRoot,\n            baseCommit: $bundle->baseCommit,\n            candidateRevision: $bundle->candidateRevision,\n            contractSource: $bundle->contractSource,\n            recallSource: $bundle->recallSource,\n            allowedScope: $bundle->allowedScope,\n            requiredValidation: $bundle->requiredValidation,\n            priorHandoff: $bundle->priorHandoff,\n            acceptedOutcomes: $bundle->acceptedOutcomes,\n            completionMarker: $bundle->completionMarker,\n            prompt: $bundle->prompt . "\\nenvironment=" . $observation->digest(),\n            environmentObservationDigest: $observation->digest(),\n        );\n    }\n\n    public function recordStageCandidate(StageCandidateObservation $observation): string\n""",
)
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    """final class ProfileHost implements HostAdapter\n{\n    public int $executions = 0;\n""",
    """final class ProfileHost implements HostAdapter\n{\n    public int $executions = 0;\n    public int $probes = 0;\n""",
)
replace_once(
    "tests/Integration/Execution/ProfileEndToEndTest.php",
    """    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability\n    {\n        return new HostAvailability('fake', 'fake', '1', null);\n    }\n""",
    """    public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability\n    {\n        ++$this->probes;\n\n        return new HostAvailability('fake', 'fake', '1', null);\n    }\n""",
)

# Real owner E2E: prove the prompt received by a host is the Loop-finalized,
# observation-bound prompt, not the earlier generic stage prompt.
replace_once(
    "tests/Integration/Execution/AgentLoopGatewayEndToEndTest.php",
    """        self::assertSame($agentStages, $host->executions);\n        self::assertSame($profile, $projection->profile->value);\n""",
    """        self::assertSame($agentStages, $host->executions);\n        self::assertSame($agentStages, $host->environmentBoundExecutions);\n        self::assertSame($profile, $projection->profile->value);\n""",
)
replace_once(
    "tests/Integration/Execution/AgentLoopGatewayEndToEndTest.php",
    """final class OutcomeHost implements HostAdapter\n{\n    public int $executions = 0;\n""",
    """final class OutcomeHost implements HostAdapter\n{\n    public int $executions = 0;\n    public int $environmentBoundExecutions = 0;\n""",
)
replace_once(
    "tests/Integration/Execution/AgentLoopGatewayEndToEndTest.php",
    """    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult\n    {\n        ++$this->executions;\n        $artifactReferences = [];\n""",
    """    public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult\n    {\n        ++$this->executions;\n        if (str_contains($request->prompt, '# Current bounded execution environment')\n            && str_contains($request->prompt, 'Observation digest: sha256:')) {\n            ++$this->environmentBoundExecutions;\n        }\n        $artifactReferences = [];\n""",
)
