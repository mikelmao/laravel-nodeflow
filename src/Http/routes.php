<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Http\Controllers\FieldOptionsController;
use Nodeflow\Http\Controllers\FlowEditorController;
use Nodeflow\Http\Controllers\RunSubjectsController;
use Nodeflow\Http\Controllers\RunViewController;

/*
 * Loaded by Nodeflow::routes(), which a host calls inside its own Route::group —
 * so prefix, middleware and domain are the host's choice, not ours. Nothing here
 * declares middleware for that reason.
 *
 * {flow} binds through the tenant-scoped Flow model, so a cross-tenant id is a 404
 * before any controller code runs. That is deliberate: a 403 would confirm the row
 * exists.
 */

Route::get('flows/{flow}/edit', [FlowEditorController::class, 'edit'])->name('nodeflow.flows.edit');
Route::put('flows/{flow}/draft', [FlowEditorController::class, 'draft'])->name('nodeflow.flows.draft');
Route::post('flows/{flow}/validate', [FlowEditorController::class, 'validate'])
    ->name('nodeflow.flows.validate');
Route::post('flows/{flow}/publish', [FlowEditorController::class, 'publish'])->name('nodeflow.flows.publish');

/*
 * Keyed by node type and field key, never by a class name. The source is read from
 * the node's own definition() — an endpoint that accepted the class from the client
 * would be "instantiate any class in this application and call options() on it".
 */
Route::get('flows/{flow}/nodes/{type}/fields/{field}/options', FieldOptionsController::class)
    ->name('nodeflow.fields.options');

/*
 * The run view (spec §6, plan 4). Read-only: there is no write path here at all.
 *
 * {run} binds through the tenant-scoped Run, so a cross-tenant id is a 404
 * before any controller code runs — same reasoning as {flow} above. {node} is a
 * graph node id, not a record id: the controller validates it against the run's
 * own pinned graph before it reaches a query.
 */
Route::get('runs/{run}', [RunViewController::class, 'show'])->name('nodeflow.runs.show');
Route::get('runs/{run}/overlay', [RunViewController::class, 'overlay'])->name('nodeflow.runs.overlay');
Route::get('runs/{run}/nodes/{node}/subjects', RunSubjectsController::class)
    ->name('nodeflow.runs.subjects');
