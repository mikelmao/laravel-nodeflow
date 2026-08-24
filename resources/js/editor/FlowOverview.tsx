export type FlowOverviewValidation = {
    status: 'unchecked' | 'checking' | 'valid' | 'warning' | 'invalid' | 'failed'
}

export type UnknownNodeTypeDiagnostic = {
    nodeId: string
    type: string
}

export type UnresolvedOutputDiagnostic = {
    from: string
    to: string
}

/** A controller decides whether an issue's node can currently be selected. */
export type FlowOverviewIssue = {
    message: string
    node: string | null
    field: string | null
    placeable: boolean
}

export type FlowOverviewProps = {
    flow: { name: string }
    trigger: { label: string; type: string } | null
    triggerReadiness?: string | null
    publishedVersion: number | null
    nodeCount: number
    connectionCount: number
    startNodeId: string | null
    validation: FlowOverviewValidation
    issues: FlowOverviewIssue[]
    warnings: string[]
    errors: string[]
    unknownTypes: UnknownNodeTypeDiagnostic[]
    unresolvedOutputs: UnresolvedOutputDiagnostic[]
    onIssueSelect?: (issue: FlowOverviewIssue) => void
}

const readinessCopy: Record<FlowOverviewValidation['status'], string> = {
    unchecked: 'Not validated yet',
    checking: 'Checking flow readiness',
    valid: 'Ready to publish',
    warning: 'Ready with warnings',
    invalid: 'Needs attention before publishing',
    failed: 'Validation could not complete',
}

function plural(count: number, noun: string): string {
    return `${count} ${noun}${count === 1 ? '' : 's'}`
}

function DiagnosticList({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <section className="space-y-1" aria-label={title}>
            <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
            <ul className="space-y-1 text-sm">{children}</ul>
        </section>
    )
}

/** No-selection inspector view. It receives only display facts, never editor/controller state. */
export function FlowOverview({
    flow,
    trigger,
    triggerReadiness = null,
    publishedVersion,
    nodeCount,
    connectionCount,
    startNodeId,
    validation,
    issues,
    warnings,
    errors,
    unknownTypes,
    unresolvedOutputs,
    onIssueSelect,
}: FlowOverviewProps) {
    return (
        <aside aria-label="Flow overview" className="space-y-5 rounded-lg border border-border bg-card p-4 text-card-foreground">
            <header className="space-y-1">
                <h2 className="text-base font-semibold">{flow.name}</h2>
                {trigger === null ? (
                    <p className="text-sm text-muted-foreground">No trigger selected</p>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">{trigger.label}</span>{' '}
                        <span className="font-mono text-xs">{trigger.type}</span>
                    </p>
                )}
            </header>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <div><dt className="text-muted-foreground">Published</dt><dd>{publishedVersion === null ? 'Not published' : `Published version ${publishedVersion}`}</dd></div>
                <div><dt className="text-muted-foreground">Start</dt><dd>Start node: {startNodeId ?? 'None'}</dd></div>
                <div><dt className="text-muted-foreground">Nodes</dt><dd>{plural(nodeCount, 'node')}</dd></div>
                <div><dt className="text-muted-foreground">Connections</dt><dd>{plural(connectionCount, 'connection')}</dd></div>
            </dl>

            <section role="status" aria-live="polite" aria-label="Flow readiness" className="rounded-md bg-muted p-3 text-sm">
                <p className={`font-medium${triggerReadiness === null ? '' : ' text-destructive'}`}>
                    {triggerReadiness ?? readinessCopy[validation.status]}
                </p>
            </section>

            {unknownTypes.length > 0 && (
                <DiagnosticList title="Unknown node types">
                    {unknownTypes.map(({ nodeId, type }) => <li key={`${nodeId}:${type}`}>Unknown node type {type} on {nodeId}</li>)}
                </DiagnosticList>
            )}
            {unresolvedOutputs.length > 0 && (
                <DiagnosticList title="Unresolved connections">
                    {unresolvedOutputs.map(({ from, to }) => <li key={`${from}:${to}`}>Connection {from} → {to} has no output</li>)}
                </DiagnosticList>
            )}
            {warnings.length > 0 && (
                <DiagnosticList title="Warnings">
                    {warnings.map((warning, index) => <li key={`${warning}-${index}`}>{warning}</li>)}
                </DiagnosticList>
            )}
            {errors.length > 0 && (
                <DiagnosticList title="Graph errors">
                    {errors.map((error, index) => <li key={`${error}-${index}`}>{error}</li>)}
                </DiagnosticList>
            )}
            {issues.length > 0 && (
                <DiagnosticList title="Issues">
                    {issues.map((issue, index) => (
                        <li key={`${issue.node ?? 'graph'}:${issue.field ?? 'node'}:${issue.message}-${index}`}>
                            {issue.placeable && issue.node !== null && onIssueSelect !== undefined ? (
                                <button
                                    type="button"
                                    className="text-left underline decoration-muted-foreground underline-offset-2 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    onClick={() => onIssueSelect(issue)}
                                >
                                    {issue.message}
                                </button>
                            ) : issue.message}
                        </li>
                    ))}
                </DiagnosticList>
            )}
        </aside>
    )
}
