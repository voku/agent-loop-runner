<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Host;

use RuntimeException;
use voku\AgentLoopRunner\Process\ProcessRequest;
use voku\AgentLoopRunner\Process\ProcessSupervisor;

abstract readonly class AbstractCliHostAdapter implements HostAdapter
{
    public function __construct(
        private string $binary,
        private BinaryLocator $binaryLocator = new BinaryLocator(),
    ) {
    }

    final public function probe(ProcessSupervisor $processSupervisor, string $workingDirectory, array $environment): HostAvailability
    {
        $path = $this->binaryLocator->locate($this->binary);
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
            return new HostAvailability(
                $this->id(),
                $path,
                null,
                trim($result->stderr) !== '' ? trim($result->stderr) : 'version probe failed with exit ' . $result->exitCode,
            );
        }

        $version = trim($result->stdout);

        return new HostAvailability($this->id(), $path, $version !== '' ? $version : null, null);
    }

    final public function execute(HostExecutionRequest $request, ProcessSupervisor $processSupervisor): HostExecutionResult
    {
        $path = $this->binaryLocator->locate($this->binary);
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
