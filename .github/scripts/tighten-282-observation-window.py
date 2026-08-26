from pathlib import Path

path = Path('src/Execution/ExecutionCoordinator.php')
text = path.read_text()
old = """                    if (!$availability->available()) {\n                        throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);\n                    }\n                    $bundle = $this->gateway->prepareStageForEnvironment(\n"""
new = """                    if (!$availability->available()) {\n                        throw new RuntimeException('HOST_UNAVAILABLE: ' . $hostId);\n                    }\n                    $candidateAfterProbe = $this->workspaces->candidateAfter($workspace);\n                    if (!hash_equals($bundle->candidateRevision, $candidateAfterProbe)) {\n                        throw new RuntimeException('STALE_WORKSPACE: host probe changed the isolated workspace before prompt finalization.');\n                    }\n                    $bundle = $this->gateway->prepareStageForEnvironment(\n"""
if text.count(old) != 1:
    raise SystemExit('expected one environment probe insertion point')
path.write_text(text.replace(old, new, 1))
