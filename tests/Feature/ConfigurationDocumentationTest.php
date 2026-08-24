<?php

function flattenNodeflowConfiguration(array $values, string $prefix = ''): array
{
    $flattened = [];

    foreach ($values as $key => $value) {
        $path = ltrim($prefix.'.'.$key, '.');

        if (is_array($value)) {
            $flattened += flattenNodeflowConfiguration($value, $path);
        } else {
            $flattened[$path] = $value;
        }
    }

    return $flattened;
}

it('keeps the configuration reference synchronized with every shipped key and default', function () {
    $root = dirname(__DIR__, 2);
    $configuration = require $root.'/config/nodeflow.php';
    $expected = [
        'tables.prefix' => 'nodeflow_',
        'retention.runs_days' => 90,
        'retention.node_executions_days' => 90,
        'limits.max_steps_per_run' => 1000,
        'limits.subject_chunk' => 500,
        'limits.audience_chunk' => 5000,
        'limits.subject_page' => 50,
        'limits.trigger_data_bytes' => 65_536,
        'webhooks.replay_window_seconds' => 300,
        'webhooks.max_body_bytes' => 1_048_576,
        'tenancy' => 'auto',
        'check_node_types_on_boot' => false,
    ];

    expect(array_keys($configuration))->toBe([
        'tables',
        'retention',
        'limits',
        'webhooks',
        'tenancy',
        'check_node_types_on_boot',
    ])->and(flattenNodeflowConfiguration($configuration))->toBe($expected);

    $docs = (string) file_get_contents($root.'/docs/gitbook/reference/configuration.md');
    preg_match_all('/^\| `nodeflow\.([^`]+)` \| ([^|]+) \| ([^|]+) \| ([^|]+) \|/m', $docs, $rows, PREG_SET_ORDER);
    $documented = [];
    foreach ($rows as $row) {
        $documented[$row[1]] = [
            'default' => trim($row[2]),
            'accepted' => trim($row[3]),
            'environment' => trim($row[4]),
        ];
    }

    expect(array_keys($documented))->toBe(array_keys($expected))
        ->and(array_column($documented, 'default', null))->toBe([
            "`'nodeflow_'`",
            '`90`',
            '`90`',
            '`1000`',
            '`500`',
            '`5000`',
            '`50`',
            '`65_536`',
            '`300`',
            '`1_048_576`',
            "`env('NODEFLOW_TENANCY', 'auto')`",
            '`false`',
        ])
        ->and($documented['tenancy']['environment'])->toBe('`NODEFLOW_TENANCY`')
        ->and(collect($documented)->except('tenancy')->pluck('environment')->unique()->all())->toBe(['None'])
        ->and($docs)->toContain('six top-level entries')
        ->toContain('four nested groups')
        ->toContain('twelve leaf keys')
        ->toContain('positive integer or a digit-only positive integer string')
        ->toContain('Numeric strings are rejected')
        ->toContain('active trigger activations')
        ->toContain('trigger-origin live runs')
        ->toContain('after the application has booted')
        ->toContain('[Writing triggers](../building-automations/writing-triggers.md)')
        ->toContain('[Authorization](../integration/authorization.md)')
        ->toContain('[Tenancy](../integration/tenancy.md)');
});
