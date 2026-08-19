<?php

return [
    'tables' => ['prefix' => 'nodeflow_'],
    'retention' => ['runs_days' => 90, 'node_executions_days' => 90],
    'limits' => ['max_steps_per_run' => 1000, 'subject_chunk' => 500],
];
