import { StrictMode, Suspense, startTransition, useState } from 'react'
import { act, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { WebhookDetails } from './WebhookDetails'

const metadata = {
    endpoint_url: 'https://example.test/hooks/token',
    active: true,
    secret_rotated_at: null,
}

function details(secret: string | null) {
    return <WebhookDetails
        metadata={metadata}
        oneTimeSecret={secret}
        rotating={false}
        rotationError={null}
        onAcknowledgeSecret={() => {}}
        onRotate={() => {}}
    />
}

afterEach(() => vi.restoreAllMocks())

describe('WebhookDetails clipboard lifecycle', () => {
    it('binds copy completion to the captured secret disclosure', async () => {
        let resolveA!: () => void
        let resolveB!: () => void
        const writeText = vi.fn()
            .mockImplementationOnce(() => new Promise<void>((resolve) => { resolveA = resolve }))
            .mockImplementationOnce(() => new Promise<void>((resolve) => { resolveB = resolve }))
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
        const view = render(details('secret-a'))
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        view.rerender(details('secret-b'))
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))

        await act(async () => resolveA())
        expect(screen.queryByText('Secret copied.')).toBeNull()

        await act(async () => resolveB())
        expect(screen.getByText('Secret copied.')).toBeInTheDocument()
        expect(writeText).toHaveBeenNthCalledWith(1, 'secret-a')
        expect(writeText).toHaveBeenNthCalledWith(2, 'secret-b')
    })

    it('keeps valid copy feedback when a different disclosure render is interrupted', async () => {
        let resolveCopy!: () => void
        let changeSecret!: (secret: string) => void
        const suspended = new Promise<void>(() => {})
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn(() => new Promise<void>((resolve) => { resolveCopy = resolve })) },
        })
        function BlockCommit({ secret }: { secret: string }) {
            if (secret === 'secret-b') throw suspended
            return null
        }
        function Harness() {
            const [secret, setSecret] = useState('secret-a')
            changeSecret = setSecret
            return <Suspense fallback={<p>Loading disclosure</p>}>
                <WebhookDetails
                    metadata={metadata}
                    oneTimeSecret={secret}
                    rotating={false}
                    rotationError={null}
                    onAcknowledgeSecret={() => {}}
                    onRotate={() => {}}
                />
                <BlockCommit secret={secret} />
            </Suspense>
        }
        render(<Harness />)
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        act(() => startTransition(() => changeSecret('secret-b')))
        expect(screen.getByText('secret-a')).toBeInTheDocument()

        await act(async () => resolveCopy())

        expect(screen.getByText('Secret copied.')).toBeInTheDocument()
    })

    it('announces a sanitized copy failure without rendering rejection details', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockRejectedValue(new Error('raw clipboard body secret-material')) },
        })
        render(details('secret-a'))

        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))

        expect(await screen.findByRole('alert', { name: 'Webhook secret copy status' })).toHaveTextContent(/could not copy/i)
        expect(screen.queryByText(/raw clipboard body|secret-material/i)).toBeNull()
    })

    it('ignores a pending copy after acknowledgement or unmount', async () => {
        let resolveCopy!: () => void
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn(() => new Promise<void>((resolve) => { resolveCopy = resolve })) },
        })
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
        const view = render(details('secret-a'))
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        view.rerender(details(null))
        await act(async () => resolveCopy())
        expect(screen.queryByText('Secret copied.')).toBeNull()

        view.rerender(details('secret-b'))
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        view.unmount()
        await act(async () => resolveCopy())
        expect(consoleError.mock.calls.flat().join(' ')).not.toMatch(/unmounted|state update/i)
    })

    it('keeps clipboard feedback active after StrictMode replays effects', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn().mockResolvedValue(undefined) },
        })
        render(<StrictMode>{details('secret-a')}</StrictMode>)

        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))

        expect(await screen.findByText('Secret copied.')).toBeInTheDocument()
    })

    it('invalidates pending clipboard feedback synchronously when the disclosure is acknowledged', async () => {
        let resolveCopy!: () => void
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: { writeText: vi.fn(() => new Promise<void>((resolve) => { resolveCopy = resolve })) },
        })
        function Harness() {
            const [secret, setSecret] = useState<string | null>('secret-a')
            return <WebhookDetails
                metadata={metadata}
                oneTimeSecret={secret}
                rotating={false}
                rotationError={null}
                onAcknowledgeSecret={() => setSecret(null)}
                onRotate={() => {}}
            />
        }
        render(<Harness />)
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        fireEvent.click(screen.getByRole('button', { name: 'Acknowledge webhook secret' }))

        await act(async () => resolveCopy())

        expect(screen.queryByText('Secret copied.')).toBeNull()
        expect(screen.queryByRole('button', { name: 'Copy webhook secret' })).toBeNull()
    })

    it('renders the tokenized POST endpoint as plaintext with distinct copy feedback', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined)
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
        render(details(null))

        expect(screen.queryByRole('link', { name: 'Webhook endpoint' })).toBeNull()
        expect(screen.getByText(metadata.endpoint_url)).toHaveAttribute('aria-label', 'Webhook endpoint')
        fireEvent.click(screen.getByRole('button', { name: 'Copy webhook endpoint' }))

        expect(await screen.findByText('Endpoint copied.')).toBeInTheDocument()
        expect(screen.queryByText('Secret copied.')).toBeNull()
    })
})
