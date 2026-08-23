# Custom node appearance

Replace the visual body of selected node cards in both the editor and run view without losing graph handles, badges, or mandatory errors.

## Define a renderer

`NodeRenderer` is a function with this exact signature. It receives a graph node's normalized card data, its palette definition when registered, selection state, and messages that apply to that node.

```tsx
import type {
    NodeRenderer,
    NodeRendererMap,
} from '@nodeflow/editor'

const MessageBody: NodeRenderer = ({ data, def, selected, errors }) => (
    <div className={selected ? 'bg-muted' : undefined}>
        <strong>{def?.label ?? data.type}</strong>
        <p>{data.id}</p>
        {data.isStart && <p>Start node</p>}
        {errors.length > 0 && <p>{errors.length} issue(s) on this node</p>}
    </div>
)

export const nodeRenderers: NodeRendererMap = {
    'app.send_message': MessageBody,
}
```

The exact type is:

```tsx
import type { ReactElement } from 'react'
import type { NodeCardData, NodeTypePayload } from '@nodeflow/editor'

export type NodeRendererProps = {
    data: NodeCardData
    def: NodeTypePayload | undefined
    selected: boolean
    errors: string[]
}

export type NodeRenderer = (props: NodeRendererProps) => ReactElement | null
export type NodeRendererMap = Record<string, NodeRenderer>
```

Check `def` before using it. An unregistered type can remain in a draft, so the package may pass `undefined` while it makes the situation visible to the author.

## Pass the same map to both views

The editor and run view accept the same `nodeRenderers` prop. Reuse the map so authors and operators see the same domain terminology.

```tsx
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'
import { nodeRenderers } from './nodeRenderers'

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} nodeRenderers={nodeRenderers} />
}
```

```tsx
import { FlowRun, type FlowRunProps } from '@nodeflow/editor'
import { nodeRenderers } from './nodeRenderers'

export default function Run(props: FlowRunProps) {
    return <FlowRun {...props} nodeRenderers={nodeRenderers} />
}
```

The package owns the wrapper, ports, full errors, and run decorations; the host renderer supplies only the body. Target and source handles remain package-owned so renderer changes cannot make a node unwirable, and the source-handle ID remains the declared output name. The package also owns the mandatory per-node error list. A host renderer may repeat errors in its body for context, but cannot hide a publish error or a run failure by omitting them.

For run views, the wrapper additionally applies overlay decorations: never-reached nodes are dimmed; reached nodes have output, waiting, error, or explicit-zero badges. The renderer supplies only the card body, so it does not need to recreate those run-state indicators.

## Next step

Use the read-only run contract and overlay semantics in [Inspecting runs](inspecting-runs.md).
