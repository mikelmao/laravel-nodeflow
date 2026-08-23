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

/** Starts desktop-safe for SSR, then follows the responsive drawer breakpoint in the browser. */
function useNarrowViewport(): boolean {
    const [isNarrow, setIsNarrow] = useState(false)

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return
        const media = window.matchMedia('(max-width: 1023px)')
        const update = () => setIsNarrow(media.matches)
        update()
        media.addEventListener?.('change', update)
        media.addListener?.(update)
        return () => {
            media.removeEventListener?.('change', update)
            media.removeListener?.(update)
        }
    }, [])

    return isNarrow
}

/** One responsive DOM shell: grid panels at large widths, focus-managed drawers below lg. */
export function EditorShell({ mode, toolbar, library, canvas, inspector, notices, className, libraryOpen, inspectorOpen, onLibraryOpenChange, onInspectorOpenChange }: EditorShellProps) {
    const isNarrow = useNarrowViewport()
    const [libraryWidth, setLibraryWidth] = useState(320)
    const [inspectorWidth, setInspectorWidth] = useState(320)
    const libraryTrigger = useRef<HTMLButtonElement>(null)
    const inspectorTrigger = useRef<HTMLButtonElement>(null)
    const libraryPanel = useRef<HTMLElement>(null)
    const inspectorPanel = useRef<HTMLElement>(null)
    const libraryHeading = useRef<HTMLHeadingElement>(null)
    const inspectorHeading = useRef<HTMLHeadingElement>(null)
    const wasLibraryOpen = useRef(false)
    const wasInspectorOpen = useRef(false)
    const activeResizeSession = useRef<ResizeDragSession | null>(null)
    const libraryDrawerOpen = isNarrow && libraryOpen && !inspectorOpen
    const inspectorDrawerOpen = isNarrow && inspectorOpen

    useEffect(() => () => activeResizeSession.current?.cleanup(), [])

    useEffect(() => {
        if (isNarrow && libraryOpen && inspectorOpen) onLibraryOpenChange(false)
    }, [isNarrow, libraryOpen, inspectorOpen, onLibraryOpenChange])

    useEffect(() => {
        if (!isNarrow) {
            wasLibraryOpen.current = false
            return
        }
        if (libraryDrawerOpen && !wasLibraryOpen.current) libraryHeading.current?.focus()
        if (!libraryDrawerOpen && wasLibraryOpen.current && libraryPanel.current?.contains(document.activeElement)) libraryTrigger.current?.focus()
        wasLibraryOpen.current = libraryDrawerOpen
    }, [isNarrow, libraryDrawerOpen])

    useEffect(() => {
        if (!isNarrow) {
            wasInspectorOpen.current = false
            return
        }
        if (inspectorDrawerOpen && !wasInspectorOpen.current) inspectorHeading.current?.focus()
        if (!inspectorDrawerOpen && wasInspectorOpen.current && inspectorPanel.current?.contains(document.activeElement)) inspectorTrigger.current?.focus()
        wasInspectorOpen.current = inspectorDrawerOpen
    }, [isNarrow, inspectorDrawerOpen])

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (!isNarrow || event.key !== 'Escape') return
            if (inspectorDrawerOpen) {
                event.preventDefault()
                onInspectorOpenChange(false)
            } else if (libraryDrawerOpen) {
                event.preventDefault()
                onLibraryOpenChange(false)
            }
        }
        document.addEventListener('keydown', onKeyDown)
        return () => document.removeEventListener('keydown', onKeyDown)
    }, [isNarrow, inspectorDrawerOpen, libraryDrawerOpen, onInspectorOpenChange, onLibraryOpenChange])

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
        if (activeResizeSession.current !== null || event.isPrimary === false || event.button !== 0) return
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

    function openLibrary() {
        if (inspectorOpen) onInspectorOpenChange(false)
        onLibraryOpenChange(true)
    }

    function openInspector() {
        if (libraryOpen) onLibraryOpenChange(false)
        onInspectorOpenChange(true)
    }

    const libraryClass = isNarrow
        ? `fixed inset-y-0 left-0 z-30 flex w-[var(--nodeflow-library-width)] max-w-[calc(100vw-2rem)] flex-col bg-background shadow-xl transition-transform motion-reduce:transition-none ${libraryDrawerOpen ? 'translate-x-0' : 'invisible pointer-events-none -translate-x-full'}`
        : 'flex min-h-0 flex-col lg:col-start-1'
    const inspectorClass = isNarrow
        ? `fixed inset-y-0 right-0 z-30 flex w-[var(--nodeflow-inspector-width)] max-w-[calc(100vw-2rem)] flex-col bg-background shadow-xl transition-transform motion-reduce:transition-none ${inspectorDrawerOpen ? 'translate-x-0' : 'invisible pointer-events-none translate-x-full'}`
        : 'flex min-h-0 flex-col lg:col-start-5'

    return <section data-testid="editor-shell" className={`${modeClass} flex flex-col ${className ?? ''}`.trim()} style={shellStyle(libraryWidth, inspectorWidth)}>
        {toolbar}
        {isNarrow && <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border bg-background px-3 py-2">
            {!libraryDrawerOpen && <button ref={libraryTrigger} type="button" aria-label="Open Node Library" title="Open Node Library" onClick={openLibrary} className="rounded-md border border-border px-2 py-1 text-sm">Node Library</button>}
            {!inspectorDrawerOpen && <button ref={inspectorTrigger} type="button" aria-label="Open Inspector" title="Open Inspector" onClick={openInspector} className="rounded-md border border-border px-2 py-1 text-sm">Inspector</button>}
        </div>}
        {notices && <div className="shrink-0">{notices}</div>}
        <div data-nodeflow-shell-body className="grid flex-1 min-h-0 grid-cols-1 lg:grid-cols-[var(--nodeflow-library-width)_4px_minmax(0,1fr)_4px_var(--nodeflow-inspector-width)]">
            <aside ref={libraryPanel} role={libraryDrawerOpen ? 'dialog' : undefined} aria-label="Node Library" aria-hidden={isNarrow && !libraryDrawerOpen ? true : undefined} inert={isNarrow && !libraryDrawerOpen} className={libraryClass}>
                {isNarrow && <div className="flex items-center justify-between border-b border-border p-3"><h2 ref={libraryHeading} tabIndex={-1} className="font-semibold">Node Library</h2><button type="button" aria-label="Close Node Library" title="Close Node Library" onClick={() => onLibraryOpenChange(false)} className="rounded p-1">Close</button></div>}
                <div className="min-h-0 grow overflow-auto">{library}</div>
            </aside>
            <div role="separator" aria-label="Resize Node Library" aria-orientation="vertical" aria-valuemin={libraryBounds.min} aria-valuemax={libraryBounds.max} aria-valuenow={libraryWidth} tabIndex={0} onKeyDown={(event) => resizeKeyboard('library', event)} onPointerDown={(event) => resizePointer('library', event)} className="hidden w-1 cursor-col-resize touch-none bg-border hover:bg-ring lg:col-start-2 lg:block" />
            <main className="relative col-start-1 min-h-0 overflow-hidden lg:col-start-3">{canvas}</main>
            <div role="separator" aria-label="Resize Inspector" aria-orientation="vertical" aria-valuemin={inspectorBounds.min} aria-valuemax={inspectorBounds.max} aria-valuenow={inspectorWidth} tabIndex={0} onKeyDown={(event) => resizeKeyboard('inspector', event)} onPointerDown={(event) => resizePointer('inspector', event)} className="hidden w-1 cursor-col-resize touch-none bg-border hover:bg-ring lg:col-start-4 lg:block" />
            <aside ref={inspectorPanel} role={inspectorDrawerOpen ? 'dialog' : undefined} aria-label="Inspector" aria-hidden={isNarrow && !inspectorDrawerOpen ? true : undefined} inert={isNarrow && !inspectorDrawerOpen} className={inspectorClass}>
                {isNarrow && <div className="flex items-center justify-between border-b border-border p-3"><h2 ref={inspectorHeading} tabIndex={-1} className="font-semibold">Inspector</h2><button type="button" aria-label="Close Inspector" title="Close Inspector" onClick={() => onInspectorOpenChange(false)} className="rounded p-1">Close</button></div>}
                <div className="min-h-0 grow overflow-auto">{inspector}</div>
            </aside>
        </div>
    </section>
}
