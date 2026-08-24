# Graph format reference

A graph is saved as editable draft JSON and copied into each immutable published `FlowVersion`. Node IDs, graph types, source keys, field keys, and output names are durable identifiers.

## Publishable example

This example assumes the host registered webhook source `shop.order_webhook`:

```json
{
  "start": "incoming-order",
  "nodes": [
    {
      "id": "incoming-order",
      "type": "core.trigger.webhook",
      "config": {"source": "shop.order_webhook"},
      "position": {"x": 180, "y": 80}
    },
    {
      "id": "wait-for-window",
      "type": "core.wait",
      "config": {"duration": "15 minutes"},
      "position": {"x": 520, "y": 80}
    },
    {
      "id": "finish",
      "type": "core.exit",
      "config": {},
      "position": {"x": 860, "y": 80}
    }
  ],
  "edges": [
    {"from": "incoming-order", "output": "started", "to": "wait-for-window"},
    {"from": "wait-for-window", "output": "default", "to": "finish"}
  ]
}
```

`position` is editor layout data, not runtime behavior. Stored finite positions win on hydration, and topology placement fills only missing positions. Auto layout is the only action that repositions every node. Additional JSON properties normally round-trip semantically, but Nodeflow does not promise byte-for-byte or object-versus-empty-array preservation.

## Shape

| Root key | Draft | Publish/runtime |
| --- | --- | --- |
| `start` | Optional/nullable string; use `""` for the stable empty shape. | Must name the graph's one trigger node. |
| `nodes` | Optional/nullable Laravel array; use `[]` for empty. | Every item requires string `id` and `type`; `config` is optional/nullable array. |
| `edges` | Optional/nullable Laravel array; use `[]` for empty. | Every item requires string `from`, `to`, and `output`. |

| Node key | Meaning |
| --- | --- |
| `id` | Unique graph-local ID used by `start`, edges, overlays, and run origin/entry intent. |
| `type` | Registered executable or trigger graph type, never a PHP class name. |
| `config` | Flat authoring data validated by the registered definition. Source fields join trigger-node fields in the same array. A dotted field name is a literal key. |
| `position` | Preserved editor layout value; commonly finite `{x, y}` numbers. |

| Edge key | Meaning |
| --- | --- |
| `from` | Source node ID. |
| `output` | Source output; nullable only during draft autosave. Trigger definitions expose only `started`. |
| `to` | Existing target node ID at publication. |

Send `{ "start": "", "nodes": [], "edges": [] }` as the stable empty graph. Draft validation is structural and permits semantic incompleteness. Validate/Publish use stricter edge shape and semantic graph rules.

## Trigger start invariant

Publishing requires exactly one trigger node. `start` equals its ID, it has no incoming edges, and it has exactly one `started` edge to a registered executable node. Trigger nodes are declarative and are never executed. `StartRun`, trigger starts, and sub-flow starts persist and execute the `started` target as `engine_entry_node_id`.

The three built-in trigger graph types are `core.trigger.webhook`, `core.trigger.model_observer`, and `core.trigger.laravel_event`. Their `source` values must come from the compatible server-authored source palette; arbitrary PHP classes are invalid.

## Other publication validation

Publishing also checks:

- unique node IDs and registered graph types;
- executable cardinality (`HandlesSubject`, `HandlesAudience`, or both);
- trigger node/source combined field validation and deterministic driver descriptor validation;
- existing edge targets and declared source outputs;
- at most one outgoing edge per `(from, output)` pair;
- no directed cycle.

The validator currently does not require every executable node to be reachable and does not independently reject every absent edge `from` ID. Keep graphs connected and source IDs valid; these are limitations, not supported modeling techniques.

Two immediate branches that both target waits produce a warning because waits execute sequentially. Warnings do not block publication.

## HTTP responses

`PUT` draft accepts nullable edge output, saves the original graph array, and increments `draft_revision`. `POST` validate is non-mutating. A valid response is `{"valid":true,"warnings":[]}`; semantic failure is `422` with `The flow is not ready to publish.`, flat `errors`, structured `node_errors`, and warnings.

`POST` publish requires a nonnegative `draft_revision`, validates again, and returns the new `version` plus the unchanged monotonic revision. Semantic failure is `422`; stale revision is `409` with the winning graph/revision. First webhook publication may additionally return `webhook_url` and a one-time secret with no-store headers.

See [Publishing flows](../building-automations/publishing-flows.md) and [Routes](routes.md).
