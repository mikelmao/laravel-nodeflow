import { describe, expect, it } from 'vitest'
import * as PublicSurface from '.'
// @ts-expect-error WebhookDetailsProps contains the one-time secret and is intentionally private.
import type { WebhookDetailsProps } from '.'
// @ts-expect-error The controller result includes private inspector state and is intentionally private.
import type { UseEditorControllerResult } from '.'
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
    categoryPresentation,
    nodeSummary,
    NodeflowIcon,
    Unregistered,
    useOverlayPolling,
} from '.'
import type {
    CanvasContextValue,
    CanvasActions,
    CanvasEdge,
    CanvasNode,
    CanvasProps,
    CategoryPresentation,
    ConfigPanelProps,
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
    GraphComponentKind,
    GraphComponentPayload,
    GraphConfig,
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
    NodeIconName,
    NodeLibraryProps,
    OverlaySnapshot,
    PublishErrorBody,
    RunSubjectRow,
    RunSummary,
    RunUrls,
    TriggerPayload,
    TriggerNodeTypePayload,
    TriggerSourcePayload,
    TriggerSourcesPayload,
    ToolbarSlots,
    ValidationOutcome,
    WebhookMetadata,
} from '.'

type EveryPublicType =
    | CanvasContextValue
    | CanvasActions
    | CanvasEdge
    | CanvasNode
    | CanvasProps
    | CategoryPresentation
    | ConfigPanelProps
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
    | GraphComponentKind
    | GraphComponentPayload
    | GraphConfig
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
    | NodeIconName
    | NodeLibraryProps
    | OverlaySnapshot
    | PublishErrorBody
    | RunSubjectRow
    | RunSummary
    | RunUrls
    | TriggerPayload
    | TriggerNodeTypePayload
    | TriggerSourcePayload
    | TriggerSourcesPayload
    | ToolbarSlots
    | ValidationOutcome
    | WebhookMetadata

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
            categoryPresentation,
            nodeSummary,
            NodeflowIcon,
            Unregistered,
        ]).not.toContain(undefined)
        expect(everyPublicTypeIsNotNever).toBe(false)
        expect(flowEditorPropsHasUrls).toBe(true)
        expect('WebhookDetails' in PublicSurface).toBe(false)
    })

    // Counterfactual: ship FlowRun but forget the export and a host's run page
    // cannot import it, while every internal test still passes.
    it('exports the run view and its overlay contract', () => {
        expect([FlowRun, normalizeOverlay, decorationsFor, overlayFor, useOverlayPolling]).not.toContain(undefined)
        expect(flowRunPropsHasUrls).toBe(true)
    })
})
