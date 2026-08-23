import type { SVGProps } from 'react'

/** Dependency-free icons shared by the Studio's node categories and controls. */
export type NodeIconName =
    | 'bolt' | 'message' | 'filter' | 'calendar' | 'branch' | 'database' | 'globe' | 'user'
    | 'settings' | 'play' | 'pause' | 'check' | 'alert' | 'plus' | 'search' | 'close'
    | 'undo' | 'redo' | 'chevron-left' | 'chevron-right' | 'panel-left' | 'panel-right'
    | 'layout' | 'copy' | 'trash' | 'info'

type NodeflowIconProps = Pick<SVGProps<SVGSVGElement>, 'className'> & { name: NodeIconName }

const paths: Record<NodeIconName, React.ReactNode> = {
    bolt: <path d="m13 2-9 12h7l-1 8 9-12h-7l1-8Z" />,
    message: <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.7 9.7 0 0 1-4-.9L3 21l1.7-4.1A8.5 8.5 0 1 1 21 11.5Z" />,
    filter: <path d="M4 5h16M7 12h10m-7 7h4" />,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></>,
    branch: <path d="M6 3v12a3 3 0 0 0 3 3h9M18 6a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 18a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" />,
    database: <><ellipse cx="12" cy="5" rx="8" ry="3" /><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5m-16 7v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7" /></>,
    globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></>,
    settings: <><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.2 2.2-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5v.2h-3.2v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L6.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H5v-3.2h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 2.2-2.2.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V4h3.2v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1 2.2 2.2-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1V14h-.1a1.7 1.7 0 0 0-1.5 1Z" /></>,
    play: <path d="m8 5 11 7-11 7V5Z" />,
    pause: <path d="M8 5v14M16 5v14" />,
    check: <path d="m5 12 4 4L19 6" />,
    alert: <><path d="m12 3 10 18H2L12 3Z" /><path d="M12 9v4M12 17h.01" /></>,
    plus: <path d="M12 5v14M5 12h14" />,
    search: <><circle cx="11" cy="11" r="6" /><path d="m20 20-4-4" /></>,
    close: <path d="m6 6 12 12M18 6 6 18" />,
    undo: <path d="M9 7 4 12l5 5M5 12h9a5 5 0 0 1 5 5" />,
    redo: <path d="m15 7 5 5-5 5m4-5h-9a5 5 0 0 0-5 5" />,
    'chevron-left': <path d="m15 18-6-6 6-6" />,
    'chevron-right': <path d="m9 18 6-6-6-6" />,
    'panel-left': <><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16" /></>,
    'panel-right': <><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M15 4v16" /></>,
    layout: <><rect x="4" y="4" width="6" height="6" rx="1" /><rect x="14" y="4" width="6" height="6" rx="1" /><rect x="4" y="14" width="6" height="6" rx="1" /><rect x="14" y="14" width="6" height="6" rx="1" /></>,
    copy: <><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" /></>,
    trash: <><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3" /></>,
    info: <><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></>,
}

export function NodeflowIcon({ name, className }: NodeflowIconProps) {
    return <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" className={className} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{paths[name]}</svg>
}
