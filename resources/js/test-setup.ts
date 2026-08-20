import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// globals:false means Testing Library cannot discover a global afterEach and
// therefore cannot auto-register cleanup. Without this, mounted controls,
// canvases, effects and autosave timers leak into the next case.
afterEach(cleanup)

// React Flow needs these browser APIs to mount. The stubs make jsdom mountable,
// not measurable: browser acceptance still owns layout and interaction proof.
class ResizeObserverStub implements ResizeObserver {
    constructor(_callback: ResizeObserverCallback) {}
    observe(_target: Element, _options?: ResizeObserverOptions) {}
    unobserve(_target: Element) {}
    disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub
if (!('DOMMatrixReadOnly' in globalThis)) {
    class DOMMatrixReadOnlyStub { m22 = 1; constructor(_transform?: string) {} }
    Object.defineProperty(globalThis, 'DOMMatrixReadOnly', { value: DOMMatrixReadOnlyStub, writable: true })
}
Object.defineProperties(globalThis.HTMLElement.prototype, {
    offsetHeight: { get: () => 40 },
    offsetWidth: { get: () => 208 },
})
