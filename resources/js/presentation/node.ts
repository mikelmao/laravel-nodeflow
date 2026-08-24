import type { GraphComponentPayload, NodeCardData } from '../graph/types'
import type { NodeIconName } from './icons'

export type CategoryPresentation = {
    accent: 'sky' | 'emerald' | 'amber' | 'violet' | 'rose' | 'slate'
    icon: NodeIconName
}

export const categoryClasses = {
    sky: 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    emerald: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    amber: 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300',
    violet: 'border-violet-500/40 bg-violet-500/10 text-violet-700 dark:text-violet-300',
    rose: 'border-rose-500/40 bg-rose-500/10 text-rose-700 dark:text-rose-300',
    slate: 'border-slate-500/40 bg-slate-500/10 text-slate-700 dark:text-slate-300',
} as const

const accents: CategoryPresentation['accent'][] = ['sky', 'emerald', 'amber', 'violet', 'rose', 'slate']
const icons: NodeIconName[] = ['message', 'filter', 'calendar', 'branch', 'database', 'bolt']

export function categoryPresentation(group: string): CategoryPresentation {
    let hash = 0
    for (const character of group.trim().toLowerCase()) {
        hash = ((hash * 31) + character.charCodeAt(0)) >>> 0
    }
    const index = hash % accents.length
    return { accent: accents[index]!, icon: icons[index]! }
}

function empty(value: unknown): boolean {
    return value === null || value === undefined || value === '' || (Array.isArray(value) && value.length === 0)
}

function compact(value: unknown): string {
    if (typeof value === 'boolean') return value ? 'Yes' : 'No'
    if (Array.isArray(value)) {
        const preview = value.slice(0, 2).map(compact).join(', ')
        return value.length > 2 ? `${preview} +${value.length - 2} more` : preview
    }
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'bigint') return String(value)
    if (value instanceof Date) return value.toLocaleDateString()
    try {
        return JSON.stringify(value) ?? String(value)
    } catch {
        return String(value)
    }
}

function truncate(text: string, maximum = 78): string {
    return text.length > maximum ? `${text.slice(0, maximum - 1)}…` : text
}

/** A single human-readable configuration hint for the compact node body. */
export function nodeSummary(data: NodeCardData, def?: GraphComponentPayload): string {
    if (def === undefined) return ''

    for (const field of def.fields) {
        const value = data.config[field.key]
        if (empty(value)) {
            if (field.required) return 'Needs configuration'
            continue
        }
        return truncate(`${field.label}: ${compact(value)}`)
    }

    return def.description ?? ''
}
