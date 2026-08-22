# Graph format reference

A graph is the JSON document saved as a draft and frozen into a published flow version. Node IDs, node types, and output names are stable identifiers: changing one changes the graph contract, so preserve them when editing or migrating a flow.

## Complete example

This is a publishable graph using the built-in wait and exit nodes.

```json
{
  "start": "wait-for-window",
  "nodes": [
    {
      "id": "wait-for-window",
      "type": "core.wait",
      "config": {
        "duration": "15 minutes"
      },
      "position": {
        "x": 180,
        "y": 80
      }
    },
    {
      "id": "finish",
      "type": "core.exit",
      "config": {},
      "position": {
        "x": 520,
        "y": 80
      }
    }
  ],
  "edges": [
    {
      "from": "wait-for-window",
      "output": "default",
      "to": "finish"
    }
  ]
}
```

`position` is not interpreted by the runtime, but the draft and publish endpoints save the original graph payload rather than a stripped validation result, so it is preserved for the canvas. Other unlisted node and edge properties are likewise round-tripped; only the keys below have package graph semantics.

## Shape

| Root key | JSON type | Draft request | Publish request and runtime meaning |
| --- | --- | --- | --- |
| `start` | string | Optional and nullable. A missing value becomes `""` when the graph is read. | Must name an existing node; an empty or missing start is a publish error. |
| `nodes` | array of node objects | Optional and nullable. A missing value becomes `[]` when read. | Each node requires `id` and `type`; `config` is optional/nullable. |
| `edges` | array of edge objects | Optional and nullable. A missing value becomes `[]` when read. | Each edge requires `from`, `to`, and `output`. |

| Node key | JSON type | Requirement |
| --- | --- | --- |
| `id` | string | Required. It is the graph-local node identifier, used by `start`, edges, runtime cursors, and overlay keys. It must be unique when publishing. |
| `type` | string | Required. It is the registered node type, such as `core.wait`, not a PHP class name. It must resolve to an executable registered node when publishing. |
| `config` | object | Optional and nullable. If omitted or null, node validation receives `[]`. Its keys and values are defined by that node's `definition()` and `validate()` methods. |
| `position` | Any JSON value; commonly `{ "x": number, "y": number }` | Not package-validated and not runtime-interpreted; preserved for editor layout. |

| Edge key | JSON type | Requirement |
| --- | --- | --- |
| `from` | string | Required. Source node ID. |
| `output` | string | Required for publish. Nullable for draft autosave only, so a half-dragged connection can be saved. It must be an output declared by the source node when that source exists and resolves. |
| `to` | string | Required. Target node ID. It must exist when publishing. |

The HTTP validation accepts strings and arrays only at these structural boundaries. It deliberately leaves graph meaning to publishing, and it does not remove additional properties from the persisted payload.

## Publish validation

Publishing checks all of the following:

- `start` is present and names an existing node.
- Node IDs are unique.
- Every node type is registered and implements `HandlesSubject`, `HandlesAudience`, or both.
- Each node's own configuration validation passes.
- Every edge target exists.
- For an existing, registered source node, `output` is one of that node's declared outputs.
- Each `(from, output)` pair has at most one edge. A subject cannot fan out down parallel paths from one output.
- The graph is acyclic.

The current validator does **not** require every node to be reachable from `start`, and it does **not** reject an edge whose `from` ID is absent. An absent source consequently has no source-output check; its edge can still participate in the duplicate-output and cycle scans. Do not rely on either omission as a supported modelling feature.

The sole current warning is emitted when one source has at least two distinct outputs whose immediate target nodes are `core.wait`: those waits run sequentially in the interpreter, so elapsed time is the sum rather than the maximum. Warnings do not block publication and are not included in the current publish response.

## Drafts, publishing, and errors

**Outcome:** a draft can be structurally incomplete, while a published graph is a validated immutable version.

`PUT` to the draft route checks the structural request rules above, with `edges.*.output` nullable, then saves the supplied graph and increments `draft_revision`. It does not run graph semantic validation. This permits a graph mid-edit, but not a malformed node without a string `id` or `type`, nor an edge without string `from` and `to`.

`POST` to the publish route requires a non-null string `edges.*.output`, then runs the semantic rules. On success it creates the next version, makes it current, sets the flow status to `active`, and clears the draft. It leaves `draft_revision` monotonic rather than resetting it.

A semantic publish failure is a `422` response in this shape:

```json
{
  "message": "The flow could not be published.",
  "errors": [
    "Node [wait-for-window] field [duration]: The duration field is required."
  ],
  "node_errors": [
    {
      "node": "wait-for-window",
      "field": "duration",
      "message": "The duration field is required."
    }
  ]
}
```

`node_errors` entries use `node`, `field`, and `message`; `node` and `field` can be `null` for graph-wide errors such as a missing start or a cycle. Structural request failures use Laravel's normal validation-error response. A stale draft is instead a `409` containing `message`, a graph-shaped winning `graph`, and `draft_revision`. See [Routes](routes.md#error-responses) for the endpoints and authorization boundary.

## Next step

Read [Publishing flows](../building-automations/publishing-flows.md) for version lifecycle, and [Core nodes](core-nodes.md) for the supplied node configurations and outputs.
