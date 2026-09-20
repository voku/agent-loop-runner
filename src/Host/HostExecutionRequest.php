<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use InvalidArgumentException;
use voku\AgentLoopRunner\Process\NullProcessObserver;
use voku\AgentLoopRunner\Process\ProcessObserver;

final readonly class HostExecutionRequest
{
    /** @var non-empty-string */
    public string $roleId;
    /** @var non-empty-string */
    public string $workingDirectory;
    /** @var non-empty-string */
    public string $prompt;
    /** @var array<string, string> */
    public array $environment;
    public int $timeoutSeconds;
    public ProcessObserver $observer;
    /** @var non-empty-string|null */
    public ?string $model;
    /** @var non-empty-string|null */
    public ?string $reasoningEffort;

    /** @param array<string, string> $environment */
    public function __construct(
        string $roleId,
        string $workingDirectory,
        string $prompt,
        array $environment,
        int $timeoutSeconds,
        ProcessObserver $observer = new NullProcessObserver(),
        ?string $model = null,
        ?string $reasoningEffort = null,
    )
    {
        if ($roleId === '' || $workingDirectory === '' || $prompt === '' || $timeoutSeconds < 1) {
            throw new InvalidArgumentException('Host execution requires role, working directory, prompt, and a positive timeout.');
        }
        if ($model !== null && $model === '') {
            throw new InvalidArgumentException('Host execution model must be non-empty when supplied.');
        }
        if ($reasoningEffort !== null && preg_match('/^[a-z][a-z0-9_-]*$/', $reasoningEffort) !== 1) {
            throw new InvalidArgumentException('Host execution reasoning effort must be a safe lowercase identifier.');
        }
        $this->roleId = $roleId;
        $this->workingDirectory = $workingDirectory;
        $this->prompt = $prompt;
        $this->environment = $environment;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->observer = $observer;
        $this->model = $model;
        $this->reasoningEffort = $reasoningEffort;
    }
}
