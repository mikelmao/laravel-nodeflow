import { useState } from 'react'
import type { NodeDataContext } from './nodeData'

export function AvailableData({ data }: { data: NodeDataContext }) {
    const [search, setSearch] = useState('')
    const fields = data.fields.filter(field => `${field.key} ${field.label} ${field.origin} ${field.description}`.toLowerCase().includes(search.trim().toLowerCase()))
    const origins = [...new Set(fields.map(field => field.origin))]
    return <details className="group rounded-lg border border-border bg-muted/20">
        <summary className="cursor-pointer rounded-lg px-3 py-3 text-sm font-medium text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
            Available data <span className="ml-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-normal text-muted-foreground">{data.fields.length}</span>
        </summary>
        <div className="space-y-3 border-t border-border px-3 pb-3 pt-3">
            <p className="text-xs leading-relaxed text-muted-foreground">{data.summary}</p>
            <p className="text-[10px] text-muted-foreground">Example values only · no live customer data</p>
            {data.fields.length > 0 && <input type="search" aria-label="Search available data" placeholder="Find a field…" value={search} onChange={event => setSearch(event.target.value)} className="w-full rounded-md border border-input bg-background px-3 py-2 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" />}
            {origins.map(origin => <section key={origin} className="space-y-2">
                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{origin}</h3>
                {fields.filter(field => field.origin === origin).map((field) => <article key={field.key} className="space-y-1 rounded-md border border-border bg-background p-2.5">
                    <div className="flex flex-wrap items-baseline justify-between gap-1"><h4 className="text-xs font-medium">{field.label}</h4><span className="text-[10px] text-muted-foreground">{field.type}</span></div>
                    <code className="block break-all text-[11px] text-foreground">{field.availability === 'available' ? `{{ ${field.key} }}` : field.key}</code>
                    <p className="text-[11px] text-muted-foreground">{field.origin} · {field.availability === 'available' ? 'Message placeholder' : field.availability === 'conditional' ? (field.availabilityNote ?? 'Requires upstream context') : 'Reference only'}</p>
                    <p className="text-[11px] leading-relaxed text-muted-foreground">{field.description}</p>
                    <p className="break-words rounded bg-muted/50 px-2 py-1 text-[11px] text-foreground">Example: {field.example}</p>
                </article>)}
            </section>)}
            {fields.length === 0 && search && <p role="status" className="text-xs text-muted-foreground">No fields match “{search}”.</p>}
        </div>
    </details>
}
