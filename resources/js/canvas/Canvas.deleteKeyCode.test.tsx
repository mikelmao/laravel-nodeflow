import { render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

const flowProps = vi.hoisted(() => [] as Array<{ deleteKeyCode?: unknown }>)

vi.mock('@xyflow/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@xyflow/react')>()
    return {
        ...actual,
        ReactFlow: (props: { deleteKeyCode?: unknown }) => {
            flowProps.push(props)
            return <div data-testid="react-flow-props" />
        },
    }
})

import { Canvas } from './Canvas'

describe('Canvas deletion key forwarding', () => {
    it('passes an explicit null delete key through to React Flow instead of restoring its default', () => {
        render(<Canvas nodes={[]} edges={[]} defs={{}} deleteKeyCode={null} />)

        expect(flowProps.at(-1)?.deleteKeyCode).toBeNull()
    })

    it('keeps the editable canvas default when deletion is omitted', () => {
        render(<Canvas nodes={[]} edges={[]} defs={{}} />)

        expect(flowProps.at(-1)?.deleteKeyCode).toEqual(['Backspace', 'Delete'])
    })
})
