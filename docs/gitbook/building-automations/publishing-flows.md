# Publishing flows

> **Experimental:** Nodeflow is pre-release software. Publishing turns author input into an executable immutable snapshot; enforce authorization before calling it directly.

Publishing validates a graph and, when it passes, creates the next immutable `FlowVersion`. A graph uses `start`, `nodes`, and `edges`; nodes require `id`, `type`, and optional `config`, while edges require `from`, `output`, and `to`.

**Graph payload partial:**

```php
$graph = [
    'start' => 'send',
    'nodes' => [
        [
            'id' => 'send',
            'type' => 'app.send_message',
            'config' => ['channel' => 'email', 'message' => 'Welcome'],
        ],
        ['id' => 'done', 'type' => 'core.exit', 'config' => []],
    ],
    'edges' => [
        ['from' => 'send', 'output' => 'sent', 'to' => 'done'],
        ['from' => 'send', 'output' => 'failed', 'to' => 'done'],
    ],
];
```

## Publish an authorized flow

The current call is `PublishFlow::publish(Flow $flow, array $graph, ?string $publishedBy = null): FlowVersion`.

**File: `app/Http/Controllers/FlowPublishController.php` (partial controller action; `$flow` is a tenant-scoped route model and `$graph` is the validated payload above):**

```php
<?php

use Illuminate\Support\Facades\Gate;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

/** @var Flow $flow A tenant-scoped flow resolved by the host route. */
Gate::authorize('publish', $flow);

$version = app(PublishFlow::class)->publish(
    $flow,
    $graph,
    (string) auth()->id(),
);
```

Do not call the service with a flow found from an untrusted ID or accept `flow_id`, version IDs, `tenant_id`, or `current_version_id` from a request. The service derives identifiers from the authorized `Flow`; authorization remains the host's responsibility for direct service calls.

`GraphInvalidException` is semantic validation after `Graph::fromArray()` receives a structurally valid graph. Direct callers do not receive the editor controller's request validation, so they must validate the documented array shape before calling `publish()`; malformed nodes or edges can otherwise fail before a structured graph error is available.

## Fix blocking validation errors

`GraphValidator` currently blocks publication for all of the following:

| Area | Blocking rule |
| --- | --- |
| Start | `start` must be a non-empty ID of a node in the graph. |
| Nodes | Node IDs must be unique. Each node type must be registered. Its resolved class must implement `HandlesSubject`, `HandlesAudience`, or both. |
| Fields | Each registered node's `validate($config)` result must be empty. This applies every field's required/base/options/duration rules. |
| Edges | Every edge target (`to`) must name a node in the graph. For a known source node, `output` must be one of that node definition's output names. |
| Cardinality | Each `from` + `output` pair may have only one outgoing edge; a subject cannot fan out to parallel branches. |
| Cycles | No directed cycle may occur anywhere in the graph, including disconnected components. |

There is no current reachability rule: a graph can publish with nodes or disconnected components that cannot be reached from `start`. Edge source existence is also not independently checked; an edge whose `from` is absent is only rejected if another rule (such as its missing `to`) catches it. Keep graphs connected and source IDs valid even though the current validator does not enforce those two conditions.

The one current non-blocking warning is exactly: “Two or more branches contain waits. In this version, branch waits run sequentially rather than concurrently, so total elapsed time is the sum, not the maximum.” The validator emits it only when at least two distinct outputs of one node directly target `core.wait`; it does not discover waits farther downstream. `PublishFlow` does not return warnings and still publishes such a graph.

## Handle a rejected publish

`PublishFlow` throws `GraphInvalidException` when validation fails. `errors(): array` returns a flat `string[]`. `nodeErrors(): array` returns:

```php
array<int, array{node: ?string, field: ?string, message: string}>
```

Field failures use their node ID and field key. An empty start and a cycle use `node: null` and `field: null`. A named start that does not exist is attributed to that missing node ID with `field: null`; other node-level failures also have `field: null`.

The editor's publish endpoint returns this semantic validation response with HTTP `422`:

```json
{
  "message": "The flow could not be published.",
  "errors": ["Node [send] field [channel]: The selected channel is invalid."],
  "node_errors": [
    {
      "node": "send",
      "field": "channel",
      "message": "The selected channel is invalid."
    }
  ]
}
```

Malformed HTTP graph payloads are rejected earlier by Laravel request validation with its normal HTTP `422` validation-error shape, rather than this `node_errors` response. See [Editor](../editor-and-run-view/editor.md) for how the client presents each response.

## Know what publishing changes

The successful work happens in one database transaction: it creates a `FlowVersion` with the next per-flow version number, graph, SHA-256 `content_hash`, publication time, and publisher; then it sets the flow's `current_version_id`, marks it active, and clears `draft_graph` plus `draft_updated_at`.

Nodeflow treats the version graph as immutable, and its publishing services never mutate it. The `FlowVersion` model and database do not enforce update/delete immutability, so host code must never mutate or delete a version required by a run. Runs retain their own `flow_version_id`, so old runs continue on their pinned version after a later publish. The flow's `draft_revision` is deliberately not cleared or reset; it remains monotonic for open editors and subsequent draft saves.

The publish transaction does not coordinate with draft saves from other requests. `SaveDraft` uses a revision compare-and-swap, but `PublishFlow` accepts no revision: a draft saved after the final draft `PUT` and before the publish `POST` can be cleared by publishing. Until revision-aware publishing is available, serialize every application-level draft-save and publish request per flow, or restrict the flow to one editor/author at a time. Restricting only publishers does not prevent another updater's draft race.

## Next step

Review the lifetime model in [Flows and versions](flows-and-versions.md), then exercise the new version with your application's run-start integration.
