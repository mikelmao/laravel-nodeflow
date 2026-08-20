// Pure shared geometry keeps initial placement and node rendering deterministic.
export const NODE_WIDTH = 208
export const HANDLE_ROW_HEIGHT = 20
const COLUMNS = 4
const COLUMN_GAP = 240
const ROW_GAP = 160
const ORIGIN = { x: 60, y: 60 }

export function gridPosition(index: number): { x: number; y: number } {
  return {
    x: ORIGIN.x + (index % COLUMNS) * COLUMN_GAP,
    y: ORIGIN.y + Math.floor(index / COLUMNS) * ROW_GAP,
  }
}

export function outputHandleTop(index: number): number {
  return 56 + index * HANDLE_ROW_HEIGHT
}
