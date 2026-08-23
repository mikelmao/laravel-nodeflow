import { describe, expect, it } from 'vitest'
import {
    Canvas,
    controlFor,
    decorationsFor,
    defaultControls,
    defaultNodeRenderer,
    FieldOptionsContext,
    FlowEditor,
    FlowRun,
    mergeControls,
    normalizeOverlay,
    overlayFor,
    rendererFor,
    Unregistered,
    useOverlayPolling,
} from '.'
import type {
    CanvasContextValue,
    CanvasActions,
    CanvasEdge,
    CanvasNode,
    CanvasProps,
    ControlMap,
    EditorUrls,
    EditorMode,
    EditorActions,
    EditorDocument,
    EditorSelection,
    EditorView,
    FieldControl,
    FieldControlProps,
    FieldPayload,
    FlowEditorProps,
    FlowRunProps,
    FlowSummary,
    Graph,
    GraphEdge,
    GraphNode,
    NodeBadge,
    NodeCardData,
    NodeDecoration,
    NodeDecorationMap,
    NodeErrorEntry,
    NodeflowEdge,
    NodeflowNode,
    NodeOverlay,
    NodeRenderer,
    NodeRendererMap,
    NodeRendererProps,
    NodeTypePayload,
    OverlaySnapshot,
    PublishErrorBody,
    RunSubjectRow,
    RunSummary,
    RunUrls,
    TriggerPayload,
    ToolbarSlots,
    UseEditorControllerOptions,
    UseEditorControllerResult,
    ValidationOutcome,
} from '.'

type EveryPublicType =
    | CanvasContextValue
    | CanvasActions
    | CanvasEdge
    | CanvasNode
    | CanvasProps
    | ControlMap
    | EditorUrls
    | EditorMode
    | EditorActions
    | EditorDocument
    | EditorSelection
    | EditorView
    | FieldControl
    | FieldControlProps
    | FieldPayload
    | FlowEditorProps
    | FlowRunProps
    | FlowSummary
    | Graph
    | GraphEdge
    | GraphNode
    | NodeBadge
    | NodeCardData
    | NodeDecoration
    | NodeDecorationMap
    | NodeErrorEntry
    | NodeflowEdge
    | NodeflowNode
    | NodeOverlay
    | NodeRenderer
    | NodeRendererMap
    | NodeRendererProps
    | NodeTypePayload
    | OverlaySnapshot
    | PublishErrorBody
    | RunSubjectRow
    | RunSummary
    | RunUrls
    | TriggerPayload
    | ToolbarSlots
    | UseEditorControllerOptions
    | UseEditorControllerResult
    | ValidationOutcome

type IsNever<T> = [T] extends [never] ? true : false
const everyPublicTypeIsNotNever: IsNever<EveryPublicType> = false
const flowEditorPropsHasUrls: FlowEditorProps extends { urls: EditorUrls } ? true : false = true
const flowRunPropsHasUrls: FlowRunProps extends { urls: RunUrls } ? true : false = true

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

    // Counterfactual: ship FlowRun but forget the export and a host's run page
    // cannot import it, while every internal test still passes.
    it('exports the run view and its overlay contract', () => {
        expect([FlowRun, normalizeOverlay, decorationsFor, overlayFor, useOverlayPolling]).not.toContain(undefined)
        expect(flowRunPropsHasUrls).toBe(true)
    })
})
