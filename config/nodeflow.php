<?php

return [
    'tables' => ['prefix' => 'nodeflow_'],
    'retention' => ['runs_days' => 90, 'node_executions_days' => 90],
    'limits' => ['max_steps_per_run' => 1000, 'subject_chunk' => 500, 'audience_chunk' => 5000, 'subject_page' => 50],

    /*
     * What a null return from TenantResolver::currentTenantId() means.
     *
     * 'auto' (default) — infer it. If the container holds the package's own
     *   NoTenancyResolver, the host never expressed an opinion about tenancy and a
     *   null means "this application has no tenancy": read unscoped. If the host
     *   bound its own resolver, a null means it could not be resolved — a queue
     *   worker, a console command, an unauthenticated request — and a scoped read
     *   throws rather than quietly returning every tenant's rows.
     *
     * 'disabled' — always treat null as "no tenancy" and read unscoped. The escape
     *   hatch for a host that binds a resolver and genuinely wants that.
     *
     * 'resolver' — always treat null as unresolved and throw.
     *
     * A non-null tenant always scopes, in every mode. This setting governs only
     * what null means. An unrecognised value throws rather than degrading.
     */
    'tenancy' => env('NODEFLOW_TENANCY', 'auto'),

    /*
     * When enabled, check that all node types referenced by versions with live runs
     * still resolve at boot time. If unresolvable types are found, an error is logged
     * (the application does not fail). This is intended for worker and deploy contexts
     * where startup-visible failures catch configuration issues before the application
     * handles traffic. Defaults to false to avoid the query cost in every web request.
     */
    'check_node_types_on_boot' => false,
];
