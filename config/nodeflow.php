<?php

return [
    'tables' => ['prefix' => 'nodeflow_'],
    'retention' => ['runs_days' => 90, 'node_executions_days' => 90],
    'limits' => ['max_steps_per_run' => 1000, 'subject_chunk' => 500, 'audience_chunk' => 5000],

    /*
     * What a null return from TenantResolver::currentTenantId() means.
     *
     * 'disabled' — the application has no tenancy, so a null tenant reads
     *   unscoped. This is the default because the package's own fallback
     *   TenantResolver returns null, and a single-tenant host that never binds
     *   a resolver must work out of the box.
     *
     * 'resolver' — the application has tenancy, so a null tenant means it could
     *   not be resolved: a queue worker, a console command, an unauthenticated
     *   request. Scoped reads throw rather than silently returning every
     *   tenant's rows. The package's own cross-tenant reads are unaffected —
     *   they opt out with withoutTenancy() explicitly.
     *
     * A non-null tenant always scopes, in both modes. This setting governs only
     * what null means.
     */
    'tenancy' => env('NODEFLOW_TENANCY', 'disabled'),

    /*
     * When enabled, check that all node types referenced by versions with live runs
     * still resolve at boot time. If unresolvable types are found, an error is logged
     * (the application does not fail). This is intended for worker and deploy contexts
     * where startup-visible failures catch configuration issues before the application
     * handles traffic. Defaults to false to avoid the query cost in every web request.
     */
    'check_node_types_on_boot' => false,
];
