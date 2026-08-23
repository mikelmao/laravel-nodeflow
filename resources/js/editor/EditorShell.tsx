import { useEffect, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode } from 'react'

export type EditorMode = 'workspace' | 'embedded'

export type EditorShellProps = {
    mode: EditorMode
    toolbar: ReactNode
    library: ReactNode
    canvas: ReactNode
    inspector: ReactNode
    notices?: ReactNode
    className?: string
    libraryOpen: boolean
    inspectorOpen: boolean
    onLibraryOpenChange: (open: boolean) => void
    onInspectorOpenChange: (open: boolean) => void
}

const libraryBounds = { min: 240, max: 400 }
const inspectorBounds = { min: 288, max: 480 }
type ResizeDragSession = {
    which: 'library' | 'inspector'
    startX: number
    startWidth: number
    pointerId: number
    target: HTMLDivElement
    cleanup: () => void
}

function clamp(value: number, bounds: { min: number; max: number }): number {
    return Math.max(bounds.min, Math.min(bounds.max, value))
}

function shellStyle(libraryWidth: number, inspectorWidth: number): CSSProperties {
    return {
        '--nodeflow-library-width': `${libraryWidth}px`,
        '--nodeflow-inspector-width': `${inspectorWidth}px`,
    } as CSSProperties
}

/** One responsive DOM shell: grid panels at large widths, focus-managed drawers below lg. */
export function EditorShell({ mode, toolbar, library, canvas, inspector, notices, className, libraryOpen, inspectorOpen, onLibraryOpenChange, onInspectorOpenChange }: EditorShellProps) {
    const [libraryWidth, setLibraryWidth] = useState(320)
    const [inspectorWidth, setInspectorWidth] = useState(320)
    const libraryTrigger = useRef<HTMLButtonElement>(null)
    const inspectorTrigger = useRef<HTMLButtonElement>(null)
    const libraryPanel = useRef<HTMLElement>(null)
    const inspectorPanel = useRef<HTMLElement>(null)
    const libraryHeading = useRef<HTMLHeadingElement>(null)
    const inspectorHeading = useRef<HTMLHeadingElement>(null)
    const wasLibraryOpen = useRef(libraryOpen)
    const wasInspectorOpen = useRef(inspectorOpen)
    const activeResizeSession = useRef<ResizeDragSession | null>(null)

    useEffect(() => () => activeResizeSession.current?.cleanup(), [])

    useEffect(() => {
        if (libraryOpen && !wasLibraryOpen.current) libraryHeading.current?.focus()
        if (!libraryOpen && wasLibraryOpen.current && libraryPanel.current?.contains(document.activeElement)) libraryTrigger.current?.focus()
        wasLibraryOpen.current = libraryOpen
    }, [libraryOpen])

    useEffect(() => {
        if (inspectorOpen && !wasInspectorOpen.current) inspectorHeading.current?.focus()
        if (!inspectorOpen && wasInspectorOpen.current && inspectorPanel.current?.contains(document.activeElement)) inspectorTrigger.current?.focus()
        wasInspectorOpen.current = inspectorOpen
    }, [inspectorOpen])

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (event.key !== 'Escape') return
            if (inspectorOpen) {
                event.preventDefault()
                onInspectorOpenChange(false)
            } else if (libraryOpen) {
                event.preventDefault()
                onLibraryOpenChange(false)
            }
        }
        document.addEventListener('keydown', onKeyDown)
        return () => document.removeEventListener('keydown', onKeyDown)
    }, [inspectorOpen, libraryOpen, onInspectorOpenChange, onLibraryOpenChange])

    function resize(which: 'library' | 'inspector', delta: number) {
        if (which === 'library') setLibraryWidth((value) => clamp(value + delta, libraryBounds))
        else setInspectorWidth((value) => clamp(value - delta, inspectorBounds))
    }

    function resizeKeyboard(which: 'library' | 'inspector', event: ReactKeyboardEvent<HTMLDivElement>) {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
        event.preventDefault()
        resize(which, event.key === 'ArrowRight' ? 16 : -16)
    }

    function resizePointer(which: 'library' | 'inspector', event: ReactPointerEvent<HTMLDivElement>) {
        activeResizeSession.current?.cleanup()
        const target = event.currentTarget
        const session: ResizeDragSession = {
            which,
            startX: event.clientX,
            startWidth: which === 'library' ? libraryWidth : inspectorWidth,
            pointerId: event.pointerId,
            target,
            cleanup: () => undefined,
        }
        target.setPointerCapture?.(session.pointerId)
        const move = (moveEvent: PointerEvent) => {
            if (moveEvent.pointerId !== session.pointerId) return
            const delta = moveEvent.clientX - session.startX
            const nextWidth = session.which === 'library'
                ? session.startWidth + delta
                : session.startWidth - delta
            if (session.which === 'library') setLibraryWidth(clamp(nextWidth, libraryBounds))
            else setInspectorWidth(clamp(nextWidth, inspectorBounds))
        }
        const cleanup = () => {
            session.target.releasePointerCapture?.(session.pointerId)
            document.removeEventListener('pointermove', move)
            document.removeEventListener('pointerup', finish)
            document.removeEventListener('pointercancel', finish)
            if (activeResizeSession.current === session) activeResizeSession.current = null
        }
        const finish = (finishEvent: PointerEvent) => {
            if (finishEvent.pointerId !== session.pointerId) return
            cleanup()
        }
        session.cleanup = cleanup
        activeResizeSession.current = session
        document.addEventListener('pointermove', move)
        document.addEventListener('pointerup', finish)
        document.addEventListener('pointercancel', finish)
    }

    const modeClass = mode === 'workspace'
        ? 'h-dvh min-h-[42rem] overflow-hidden'
        : 'min-h-[42rem] overflow-hidden rounded-xl border bg-background'

    return <section data-testid="editor-shell" className={`${modeClass} ${className ?? ''}`.trim()} style={shellStyle(libraryWidth, inspectorWidth)}>
        <div className="flex min-h-0 items-center justify-between gap-2 border-b border-border bg-background px-3 py-2 lg:hidden">
            {!libraryOpen && <button ref={libraryTrigger} type="button" aria-label="Open Node Library" title="Open Node Library" onClick={() => onLibraryOpenChange(true)} className="rounded-md border border-border px-2 py-1 text-sm">Node Library</button>}
            {!inspectorOpen && <button ref={inspectorTrigger} type="button" aria-label="Open Inspector" title="Open Inspector" onClick={() => onInspectorOpenChange(true)} className="rounded-md border border-border px-2 py-1 text-sm">Inspector</button>}
        </div>
        <div className="grid h-full min-h-0 grid-rows-[auto_minmax(0,1fr)] lg:grid-cols-[var(--nodeflow-library-width)_minmax(0,1fr)_var(--nodeflow-inspector-width)] lg:grid-rows-[auto_minmax(0,1fr)]">
            <div className="col-span-full">{toolbar}</div>
            <aside ref={libraryPanel} role="dialog" aria-label="Node Library" aria-modal={libraryOpen || undefined} className={`fixed inset-y-0 left-0 z-30 flex w-[var(--nodeflow-library-width)] max-w-[calc(100vw-2rem)] flex-col bg-background shadow-xl transition-transform motion-reduce:transition-none lg:static lg:row-start-2 lg:translate-x-0 lg:shadow-none ${libraryOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex items-center justify-between border-b border-border p-3 lg:hidden"><h2 ref={libraryHeading} tabIndex={-1} className="font-semibold">Node Library</h2><button type="button" aria-label="Close Node Library" title="Close Node Library" onClick={() => onLibraryOpenChange(false)} className="rounded p-1">Close</button></div>
                <div className="min-h-0 grow overflow-auto">{library}</div>
            </aside>
            <div role="separator" aria-label="Resize Node Library" aria-orientation="vertical" aria-valuemin={libraryBounds.min} aria-valuemax={libraryBounds.max} aria-valuenow={libraryWidth} tabIndex={0} onKeyDown={(event) => resizeKeyboard('library', event)} onPointerDown={(event) => resizePointer('library', event)} className="col-start-2 row-start-2 hidden w-1 cursor-col-resize touch-none bg-border hover:bg-ring lg:block" />
            <main className="relative col-start-1 row-start-2 min-h-0 lg:col-start-2">{canvas}{notices && <div className="pointer-events-none absolute inset-x-3 top-3 z-10"><div className="pointer-events-auto">{notices}</div></div>}</main>
            <aside ref={inspectorPanel} role="dialog" aria-label="Inspector" aria-modal={inspectorOpen || undefined} className={`fixed inset-y-0 right-0 z-30 flex w-[var(--nodeflow-inspector-width)] max-w-[calc(100vw-2rem)] flex-col bg-background shadow-xl transition-transform motion-reduce:transition-none lg:static lg:col-start-3 lg:row-start-2 lg:translate-x-0 lg:shadow-none ${inspectorOpen ? 'translate-x-0' : 'translate-x-full'}`}>
                <div className="flex items-center justify-between border-b border-border p-3 lg:hidden"><h2 ref={inspectorHeading} tabIndex={-1} className="font-semibold">Inspector</h2><button type="button" aria-label="Close Inspector" title="Close Inspector" onClick={() => onInspectorOpenChange(false)} className="rounded p-1">Close</button></div>
                <div className="min-h-0 grow overflow-auto">{inspector}</div>
            </aside>
            <div role="separator" aria-label="Resize Inspector" aria-orientation="vertical" aria-valuemin={inspectorBounds.min} aria-valuemax={inspectorBounds.max} aria-valuenow={inspectorWidth} tabIndex={0} onKeyDown={(event) => resizeKeyboard('inspector', event)} onPointerDown={(event) => resizePointer('inspector', event)} className="col-start-3 row-start-2 hidden w-1 cursor-col-resize touch-none bg-border hover:bg-ring lg:block" />
        </div>
    </section>
}
