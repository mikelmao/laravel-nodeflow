# Publishing flows

> **Experimental:** publication turns author input into immutable executable and trigger-routing snapshots. Authorize the flow before calling the service directly.

## Publish a trigger-first graph

```php
$graph = [
    'start' => 'incoming-order',
    'nodes' => [
        [
            'id' => 'incoming-order',
            'type' => 'core.trigger.webhook',
            'config' => ['source' => 'shop.order_webhook'],
        ],
        [
            'id' => 'done',
            'type' => 'core.exit',
            'config' => [],
        ],
    ],
    'edges' => [
        ['from' => 'incoming-order', 'output' => 'started', 'to' => 'done'],
    ],
];
```

The source key must be registered and compatible before validation. Drafts may be incomplete; publication requires exactly one trigger node, `start` equal to its ID, no incoming trigger edge, and exactly one `started` edge to an executable node.

## Authorize and publish

```php
use Illuminate\Support\Facades\Gate;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

/** @var Flow $flow A tenant-scoped flow resolved by trusted host code. */
Gate::authorize('publish', $flow);

$result = app(PublishFlow::class)->publish(
    $flow,
    $graph,
    publishedBy: (string) auth()->id(),
    expectedDraftRevision: (int) $flow->draft_revision,
);

$version = $result->version;
```

The exact signature is:

```php
public function publish(
    Flow $flow,
    array $graph,
    ?string $publishedBy = null,
    ?int $expectedDraftRevision = null,
): PublishResult;
```

Omitting `expectedDraftRevision` uses the trusted model's current revision. HTTP publication always requires and passes the author's revision. The transaction locks the flow row and refuses a mismatch with `StaleDraftException`, so a newer draft is never silently cleared.

Direct callers must validate the documented request array shape themselves. The editor controller supplies structural HTTP validation; `GraphInvalidException` represents semantic validation after `Graph::fromArray()`.

## Blocking validation

Publication rejects:

- missing/nonexistent start, any count other than one trigger, or a start that is not that trigger;
- an incoming trigger edge, a trigger without exactly one `started` target, or a target that is not executable;
- duplicate node IDs, unknown executable/trigger types, incompatible/unregistered drivers or sources, invalid fields, or an invalid compiled descriptor;
- missing edge targets, undeclared outputs, more than one target for a `(from, output)` pair, or a directed cycle.

Disconnected nodes and an edge whose `from` ID is absent remain current validator limitations; do not rely on either. Branch waits remain sequential and can produce the documented non-blocking warning. Validate is non-mutating; Publish performs validation again.

`GraphInvalidException::errors()` returns flat messages and `nodeErrors()` returns `{node, field, message}` entries with nullable node/field for graph-level problems. The HTTP publish endpoint maps it to `422`; stale revision is `409` with the winning graph and revision.

## Atomic result

One successful transaction:

1. creates the next immutable `FlowVersion` and content hash;
2. compiles and stores one immutable `TriggerActivation` for that exact version;
3. for a webhook activation, creates stable encrypted credentials only when the flow has none;
4. sets `current_version_id`, marks the flow active, and clears draft graph/timestamp without resetting the monotonic revision.

`PublishResult` exposes `version`, nullable `webhookUrl`, and nullable one-time `webhookSecret`. The secret is non-null only when credentials were created. Later publication does not reveal it.

Publication replaces the flow's current activation, but old versions and existing runs remain pinned. Host code must not mutate or delete published versions required by live runs; query-builder/raw updates bypass model immutability guards.

See [Flows and versions](flows-and-versions.md) for lifecycle details and [Graph format](../reference/graph-format.md) for the wire shape.
