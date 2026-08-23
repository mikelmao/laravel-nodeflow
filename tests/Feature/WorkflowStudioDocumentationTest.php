<?php

function workflowStudioDoc(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/docs/gitbook/{$path}");
}

it('documents the Workflow Studio editor and its non-mutating validation contract', function () {
    $editor = workflowStudioDoc('editor-and-run-view/editor.md');
    $appearance = workflowStudioDoc('editor-and-run-view/custom-node-appearance.md');
    $integration = workflowStudioDoc('integration/routes-and-inertia.md');
    $routes = workflowStudioDoc('reference/routes.md');
    $graph = workflowStudioDoc('reference/graph-format.md');
    $testing = workflowStudioDoc('contributing/testing.md');

    expect($integration)->toContain('eight routes')
        ->and($routes)->toContain('Nodeflow registers eight routes')
        ->and($routes)->toContain('| `POST` | `flows/{flow}/validate` | `nodeflow.flows.validate` |')
        ->and($routes)->toContain('`publish` / `nodeflow.publish`')
        ->and($routes)->toContain('{"valid":true,"warnings":[]}')
        ->and($routes)->toContain('The flow is not ready to publish.')
        ->and($routes)->toContain('does not save a draft or create a version')
        ->and($editor)->toContain('full-height workspace')
        ->and($editor)->toContain('<FlowEditor {...props} mode="embedded" />')
        ->and($editor)->toContain('toolbarSlots')
        ->and($editor)->toContain('leading')
        ->and($editor)->toContain('trailing')
        ->and($editor)->toContain("import { Link } from '@inertiajs/react'")
        ->and($editor)->toContain('href="/admin/flows"')
        ->and($editor)->toContain('Node Library')
        ->and($editor)->toContain('drag')
        ->and($editor)->toContain('Undo')
        ->and($editor)->toContain('Redo')
        ->and($editor)->toContain('Auto layout')
        ->and($editor)->toContain('Fit')
        ->and($editor)->toContain('minimap')
        ->and($editor)->toContain('`Cmd/Ctrl+Z` and `Cmd/Ctrl+Shift+Z`')
        ->and($editor)->not->toContain('Cmd/Ctrl+Y')
        ->and($editor)->toContain('Validate does not save a draft, create a version, or publish')
        ->and($editor)->toContain('Publish always validates again')
        ->and($appearance)->toContain('package owns the wrapper, ports, full errors, and run decorations')
        ->and($appearance)->toContain('host renderer supplies only the body')
        ->and($graph)->toContain('Stored finite positions win on hydration')
        ->and($graph)->toContain('Auto layout is the only action that repositions every node')
        ->and($testing)->toContain('real browser acceptance')
        ->and($testing)->toContain('No new CSS installation or frontend dependency is required')
        ->and($testing)->toContain('host theme');
});
