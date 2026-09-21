<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use RuntimeException;
use voku\AgentLoopRunner\Process\ProcessRequest;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

abstract readonly class AbstractCliHostAdapter implements HostAdapter
{
    /** @param list<non-empty-string>|null $resourceCommand */
    public function __construct(
        private string $binary,
        private BinaryLocator $binaryLocator = new BinaryLocator(),
        private ?array $resourceCommand = null,
        private HostCapacityInspector $capacityInspector = new HostCapacityInspector(),
    ) {
    }

    final public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        $path = $this->binaryLocator->locate($this->binary, $environment, $workingDirectory);
        if ($path === null) {
            return new HostAvailability($this->id(), null, null, 'binary not found');
        }
        try {
            $result = $processSupervisor->run(new ProcessRequest(
                [$path, '--version'],
                $workingDirectory,
                '',
                $environment,
                15,
            ));
        } catch (RuntimeException $exception) {
            return new HostAvailability($this->id(), $path, null, $exception->getMessage());
        }
        if (!$result->successful()) {
            $capacity = $this->capacityInspector->inspect($result->stdout, $result->stderr);

            return new HostAvailability(
                $this->id(),
                $path,
                null,
                // A provider can reject even a harmless version probe when
                // its account is exhausted. Keep that capacity observation
                // routable so the coordinator can select a fallback or emit
                // the quota-specific hard break.
                $capacity->remainingRatio !== null
                    ? null
                    : (trim($result->stderr) !== '' ? trim($result->stderr) : 'version probe failed with exit ' . $result->exitCode),
                $capacity->remainingRatio,
                $capacity->resetAt,
                $capacity->summary,
            );
        }

        $version = trim($result->stdout);
        $capacity = null;
        if ($this->resourceCommand !== null && $this->resourceCommand !== []) {
            try {
                $resourceProcess = $processSupervisor->run(new ProcessRequest(
                    $this->resourceCommand,
                    $workingDirectory,
                    '',
                    $environment,
                    15,
                ));
                $capacity = $this->capacityInspector->inspect(
                    $resourceProcess->stdout,
                    $resourceProcess->stderr,
                );
            } catch (RuntimeException $exception) {
                $capacity = $this->capacityInspector->inspect('', $exception->getMessage());
            }
        } else {
            $capacity = $this->capacityInspector->inspect($result->stdout, $result->stderr);
        }

        return new HostAvailability(
            $this->id(),
            $path,
            $version !== '' ? $version : null,
            null,
            $capacity->remainingRatio,
            $capacity->resetAt,
            $capacity->summary,
        );
    }

    final public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        $path = $this->binaryLocator->locate($this->binary, $request->environment, $request->workingDirectory);
        if ($path === null) {
            throw new RuntimeException('Host binary is unavailable for ' . $this->id() . ': ' . $this->binary);
        }

        return new HostExecutionResult(
            $this->id(),
            $processSupervisor->run(new ProcessRequest(
                $this->argv($path, $request),
                $request->workingDirectory,
                $this->stdin($request),
                $request->environment,
                $request->timeoutSeconds,
                $request->observer,
            )),
        );
    }

    /** @return non-empty-list<non-empty-string> */
    abstract protected function argv(string $binaryPath, HostExecutionRequest $request): array;

    protected function stdin(HostExecutionRequest $request): string
    {
        return '';
    }
}
