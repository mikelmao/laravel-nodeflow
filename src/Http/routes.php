<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Http\Controllers\FlowEditorController;

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
Route::post('flows/{flow}/publish', [FlowEditorController::class, 'publish'])->name('nodeflow.flows.publish');
