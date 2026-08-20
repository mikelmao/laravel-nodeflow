import { describe, expect, it } from 'vitest'
import {
    Canvas,
    controlFor,
    defaultControls,
    defaultNodeRenderer,
    FieldOptionsContext,
    FlowEditor,
    mergeControls,
    rendererFor,
    Unregistered,
} from '.'
import type {
    CanvasContextValue,
    CanvasEdge,
    CanvasNode,
    CanvasProps,
    ControlMap,
    EditorUrls,
    FieldControl,
    FieldControlProps,
    FieldPayload,
    FlowEditorProps,
    FlowSummary,
    Graph,
    GraphEdge,
    GraphNode,
    NodeCardData,
    NodeErrorEntry,
    NodeflowEdge,
    NodeflowNode,
    NodeRenderer,
    NodeRendererMap,
    NodeRendererProps,
    NodeTypePayload,
    PublishErrorBody,
    TriggerPayload,
} from '.'

type EveryPublicType =
    | CanvasContextValue
    | CanvasEdge
    | CanvasNode
    | CanvasProps
    | ControlMap
    | EditorUrls
    | FieldControl
    | FieldControlProps
    | FieldPayload
    | FlowEditorProps
    | FlowSummary
    | Graph
    | GraphEdge
    | GraphNode
    | NodeCardData
    | NodeErrorEntry
    | NodeflowEdge
    | NodeflowNode
    | NodeRenderer
    | NodeRendererMap
    | NodeRendererProps
    | NodeTypePayload
    | PublishErrorBody
    | TriggerPayload

type IsNever<T> = [T] extends [never] ? true : false
const everyPublicTypeIsNotNever: IsNever<EveryPublicType> = false
const flowEditorPropsHasUrls: FlowEditorProps extends { urls: EditorUrls } ? true : false = true

describe('package public surface', () => {
    // Direct internal imports can hide missing package exports; counterfactual consumers compile here but fail at the package root.
    it('exports every promised runtime and type including FlowEditor urls', () => {
        expect([
            Canvas,
            controlFor,
            defaultControls,
            defaultNodeRenderer,
            FieldOptionsContext,
            FlowEditor,
            mergeControls,
            rendererFor,
            Unregistered,
        ]).not.toContain(undefined)
        expect(everyPublicTypeIsNotNever).toBe(false)
        expect(flowEditorPropsHasUrls).toBe(true)
    })
})
