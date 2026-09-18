import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react'
import { inputClass } from '../controls/Field'
import type { NodeDataField } from './nodeData'

type Props = {
    id: string
    label: string
    value: string
    onChange: (value: string) => void
    fields: NodeDataField[]
    multiline?: boolean
}

type Token = { start: number; end: number; query: string }
function tokenAt(value: string, cursor: number): Token | null {
    const before = value.slice(0, cursor)
    const match = /\{\{\s*([a-zA-Z0-9_.]*)$/.exec(before)
    if (!match || /[\r\n]/.test(match[0])) return null
    const after = value.slice(cursor)
    const suffix = /^[a-zA-Z0-9_.]*\s*\}\}/.exec(after)
    return { start: match.index, end: cursor + (suffix?.[0].length ?? 0), query: match[1] ?? '' }
}

export function TemplateText({ id, label, value, onChange, fields, multiline = false }: Props) {
    const listId = `placeholder-${useId().replace(/:/g, '')}`
    const input = useRef<HTMLInputElement | HTMLTextAreaElement | null>(null)
    const menu = useRef<HTMLDivElement>(null)
    const pendingCursor = useRef<number | null>(null)
    const [cursor, setCursor] = useState(0)
    const [focused, setFocused] = useState(false)
    const [dismissed, setDismissed] = useState(true)
    const [selected, setSelected] = useState(0)
    const available = fields.filter((field) => field.availability !== 'reference')
    const token = tokenAt(value, cursor)
    const matches = available.filter((field) => `${field.key} ${field.label}`.toLowerCase().includes(token?.query.toLowerCase() ?? ''))
    const open = focused && !dismissed && token !== null
    const active = Math.min(selected, Math.max(0, matches.length - 1))
    const used = [...new Set([...value.matchAll(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g)].map((match) => match[1]!))]
    const conditional = available.filter((field) => used.includes(field.key) && field.availability === 'conditional')
    const unavailable = used.filter((key) => !available.some((field) => field.key === key))

    useLayoutEffect(() => {
        if (pendingCursor.current === null || input.current === null) return
        input.current.focus()
        input.current.setSelectionRange(pendingCursor.current, pendingCursor.current)
        pendingCursor.current = null
    }, [value])

    useEffect(() => {
        if (open) menu.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' })
    }, [active, open])

    function insert(field: NodeDataField) {
        const start = token?.start ?? cursor
        const end = token?.end ?? input.current?.selectionEnd ?? cursor
        const replacement = `{{ ${field.key} }}`
        const nextCursor = start + replacement.length
        pendingCursor.current = nextCursor
        setCursor(nextCursor)
        setDismissed(true)
        onChange(value.slice(0, start) + replacement + value.slice(end))
        input.current?.focus()
    }

    function keyDown(event: KeyboardEvent<HTMLInputElement | HTMLTextAreaElement>) {
        if (event.nativeEvent.isComposing || !open) return
        if (event.key === 'Escape') {
            event.preventDefault()
            event.stopPropagation()
            setDismissed(true)
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && matches.length > 0) {
            event.preventDefault()
            event.stopPropagation()
            setSelected((active + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length)
        } else if (event.key === 'Enter' && matches[active]) {
            event.preventDefault()
            event.stopPropagation()
            insert(matches[active])
        } else if (event.key === 'Tab') {
            setDismissed(true)
        }
    }

    const attributes = {
        id,
        'aria-label': label,
        'aria-autocomplete': 'list' as const,
        'aria-haspopup': 'listbox' as const,
        'aria-controls': open ? listId : undefined,
        'aria-expanded': open,
        'aria-activedescendant': open && matches.length > 0 ? `${listId}-${active}` : undefined,
        'aria-describedby': `${listId}-hint${unavailable.length ? ` ${listId}-warning` : ''}${conditional.length ? ` ${listId}-context` : ''}`,
        'aria-invalid': unavailable.length > 0 || undefined,
        autoComplete: 'off',
        value,
        className: `${inputClass} px-3 py-2 text-sm leading-relaxed`,
        onFocus: () => setFocused(true),
        onBlur: () => { setFocused(false); setDismissed(true) },
        onKeyDown: keyDown,
        onSelect: (event: React.SyntheticEvent<HTMLInputElement | HTMLTextAreaElement>) => {
            const next = event.currentTarget.selectionStart ?? 0
            if (next !== cursor) { setCursor(next); setDismissed(false); setSelected(0) }
        },
        onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
            setCursor(event.currentTarget.selectionStart ?? 0)
            setDismissed(false)
            setSelected(0)
            onChange(event.currentTarget.value)
        },
    }

    return <div className="space-y-2" data-nodeflow-shortcuts="off">
        {multiline
            ? <textarea {...attributes} rows={4} className={`${attributes.className} min-h-28 resize-y`} ref={(element) => { input.current = element }} />
            : <input {...attributes} type="text" role="combobox" ref={(element) => { input.current = element }} />}
        {open && <div className="overflow-hidden rounded-lg border border-border bg-background shadow-sm">
            <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2 text-[11px] text-muted-foreground">
                <span className="font-medium">Insert a placeholder</span><span aria-hidden="true">↑↓ &nbsp; Enter ↵</span>
            </div>
            <div ref={menu} id={listId} role="listbox" aria-label={`${label} placeholders`} className="max-h-56 overflow-y-auto p-1">
                {matches.map((field, index) => <div
                    id={`${listId}-${index}`} key={field.key} role="option" aria-selected={active === index}
                    onMouseDown={(event) => event.preventDefault()}
                    onMouseMove={() => setSelected(index)} onClick={() => insert(field)}
                    className={`cursor-pointer space-y-1 rounded-md px-2 py-2 ${active === index ? 'bg-accent text-accent-foreground ring-1 ring-inset ring-border' : 'text-foreground hover:bg-muted/60'}`}
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-x-2 gap-y-1"><span className="text-xs font-medium">{field.label}</span><span className="text-[10px] text-muted-foreground">{field.origin}</span></div>
                    <code className="block break-all text-[11px]">{`{{ ${field.key} }}`}</code>
                    <p className="truncate text-[11px] text-muted-foreground">Example: {field.example}</p>
                    {field.availability === 'conditional' && <p className="text-[10px] font-medium text-muted-foreground">{field.availabilityNote ?? 'Requires upstream context'}</p>}
                </div>)}
            </div>
            {matches.length === 0 && <p role="status" className="px-3 py-3 text-xs text-muted-foreground">{available.length ? 'No matching placeholders. Try a different name.' : 'No placeholders available. Check the upstream trigger and source.'}</p>}
        </div>}
        <p id={`${listId}-hint`} className="text-[11px] text-muted-foreground">Type <code className="rounded bg-muted px-1">{'{{'}</code> to insert data. Examples are illustrative.</p>
        {conditional.length > 0 && <p id={`${listId}-context`} className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">{conditional.map((field) => `${field.key}: ${field.availabilityNote ?? 'Requires upstream context'}`).join(' · ')}</p>}
        {unavailable.length > 0 && <p id={`${listId}-warning`} role="alert" className="rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-xs text-destructive">Check these placeholders for the current source: {unavailable.map((key) => `{{ ${key} }}`).join(', ')}. See Available data below.</p>}
    </div>
}
