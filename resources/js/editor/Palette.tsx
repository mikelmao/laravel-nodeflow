import type { NodeTypePayload } from '../graph/types'

export type PaletteProps = {
    palette: NodeTypePayload[]
    onAdd: (definition: NodeTypePayload) => void
}

export function Palette({ palette, onAdd }: PaletteProps) {
    const sorted = [...palette].sort((left, right) =>
        left.group.localeCompare(right.group) || left.label.localeCompare(right.label),
    )
    const groups = new Map<string, NodeTypePayload[]>()

    for (const definition of sorted) {
        const group = groups.get(definition.group)
        if (group === undefined) {
            groups.set(definition.group, [definition])
        } else {
            group.push(definition)
        }
    }

    return (
        <aside className="space-y-4 rounded-md border bg-card p-4">
            <h2 className="font-semibold">Nodes</h2>
            {palette.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No node types are registered. Register definitions with <code>Nodeflow::register([...])</code>.
                </p>
            ) : (
                [...groups].map(([group, definitions]) => (
                    <section key={group} className="space-y-2">
                        <h3 className="text-xs font-semibold uppercase text-muted-foreground">{group}</h3>
                        <div className="space-y-1">
                            {definitions.map((definition) => (
                                <button
                                    key={definition.type}
                                    type="button"
                                    title={definition.description ?? undefined}
                                    onClick={() => onAdd(definition)}
                                    className="block w-full rounded border p-2 text-left"
                                ><span>{definition.label}</span><span>{definition.type}</span></button>
                            ))}
                        </div>
                    </section>
                ))
            )}
        </aside>
    )
}
