<?php

function triggeredGraph(array $executable, string $source = 'test.orders', array $triggerConfig = []): array
{
    $oldStart = (string) ($executable['start'] ?? '');

    return [
        ...$executable,
        'start' => 'trigger',
        'nodes' => [
            ['id' => 'trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => $source, ...$triggerConfig]],
            ...($executable['nodes'] ?? []),
        ],
        'edges' => [
            ['from' => 'trigger', 'output' => 'started', 'to' => $oldStart],
            ...($executable['edges'] ?? []),
        ],
    ];
}

function triggeredExitGraph(string $source = 'test.orders', array $triggerConfig = []): array
{
    return triggeredGraph([
        'start' => 'first-action',
        'nodes' => [['id' => 'first-action', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ], $source, $triggerConfig);
}
