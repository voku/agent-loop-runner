<?php

declare(strict_types=1);

namespace voku\AgentLoopRunner\Config;

use RuntimeException;
use voku\AgentLoopRunner\Git\GitCommand;
use voku\AgentLoopRunner\Process\EnvironmentProjector;
use voku\AgentLoopRunner\Process\ForegroundProcessSupervisor;

/** Prevent repository contributors from supplying executable host configuration. */
final class RunnerConfigTrustGuard
{
    public function assertOperatorOwned(string $projectRoot): void
    {
        $root = realpath($projectRoot);
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Runner config trust check cannot resolve the project root.');
        }

        $configDirectory = $root . '/.agent-loop-runner';
        if (is_link($configDirectory) || is_link($configDirectory . '/config.json')) {
            throw new RuntimeException('Refusing symbolic-link runner configuration; keep operator configuration in a regular untracked file.');
        }

        $git = new GitCommand(
            new ForegroundProcessSupervisor(),
            (new EnvironmentProjector())->project(['PATH', 'HOME']),
        );
        $topLevel = $git->run($root, ['rev-parse', '--show-toplevel'], 10);
        if (!$topLevel->successful()) {
            throw new RuntimeException('Runner config is executable operator configuration and requires a Git worktree so tracked files can be rejected.');
        }
        $resolvedTopLevel = realpath(trim($topLevel->stdout));
        if (!is_string($resolvedTopLevel) || $resolvedTopLevel !== $root) {
            throw new RuntimeException('Runner config may only be loaded from the Git worktree root.');
        }

        // Run from the config directory so a nested Git repository or submodule
        // cannot make its tracked config look untracked in the outer project.
        $tracked = $git->run($configDirectory, ['ls-files', '--error-unmatch', '--', 'config.json'], 10);
        if ($tracked->successful()) {
            throw new RuntimeException(
                'Refusing repository-tracked .agent-loop-runner/config.json: it can select executable provider binaries '
                . 'and capacity probes that receive credentials. Keep operator configuration untracked.',
            );
        }
        if ($tracked->exitCode !== 1) {
            $detail = trim($tracked->stderr);
            throw new RuntimeException(
                'Unable to verify whether .agent-loop-runner/config.json is repository-tracked.'
                . ($detail !== '' ? ' ' . $detail : ''),
            );
        }
    }
}
