<?php

return [
    'tables' => ['prefix' => 'nodeflow_'],
    'retention' => ['runs_days' => 90, 'node_executions_days' => 90],
    'limits' => ['max_steps_per_run' => 1000, 'subject_chunk' => 500, 'audience_chunk' => 5000],

    /*
     * When enabled, check that all node types referenced by versions with live runs
     * still resolve at boot time. If unresolvable types are found, an error is logged
     * (the application does not fail). This is intended for worker and deploy contexts
     * where startup-visible failures catch configuration issues before the application
     * handles traffic. Defaults to false to avoid the query cost in every web request.
     */
    'check_node_types_on_boot' => false,
];
