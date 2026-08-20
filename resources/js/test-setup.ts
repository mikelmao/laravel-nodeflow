import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// globals:false means Testing Library cannot discover a global afterEach and
// therefore cannot auto-register cleanup. Without this, mounted controls,
// canvases, effects and autosave timers leak into the next case.
afterEach(cleanup)
