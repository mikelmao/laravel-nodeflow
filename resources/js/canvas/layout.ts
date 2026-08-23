// Shared geometry keeps topology placement and node rendering in lockstep.
export const NODE_WIDTH = 256
export const NODE_MIN_HEIGHT = 112
export const LAYER_GAP = 152
export const ROW_GAP = 48
export const COMPONENT_GAP = 96
export const CANVAS_ORIGIN = { x: 72, y: 88 }
export const HANDLE_ROW_HEIGHT = 28

export function outputHandleTop(index: number, count: number): number {
  return count === 1 ? NODE_MIN_HEIGHT / 2 : 64 + index * HANDLE_ROW_HEIGHT
}
