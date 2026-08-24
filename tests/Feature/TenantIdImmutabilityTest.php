<?php

use Nodeflow\Models\Flow;

// This file was planned to hold three tests (see task-14-brief.md). Two of them
// duplicate coverage already in tests/Feature/TenancyTest.php:
//
//   - "refuses a tenant_id change through the model" duplicates
//     "refuses to move an existing row to another tenant on update" (line 157):
//     both create a Flow, update its tenant_id to a different tenant, assert
//     CrossTenantWriteException is thrown, and assert the row is unchanged.
//   - "allows an update that re-sends the row's existing tenant_id" duplicates
//     "allows an update that re-sends the rows existing tenant_id" (line 206):
//     both update with the row's own tenant_id plus another field and assert
//     the other field's change persisted.
//
// Adding second copies of those would only pad the count, so this file carries
// only the one genuinely new case: the query-builder bypass. See
// task-14-report.md for the duplication check.

it('does NOT catch a tenant_id change made through the query builder', function () {
    // This test pins a documented limitation rather than a guarantee, which is
    // deliberate: the guard is an `updating` model hook, so a query-builder
    // update fires no model events and bypasses it entirely.
    //
    // Counterfactual, and the reason this test exists: change the guard's
    // mechanism so it *does* catch this — and this test fails, forcing whoever
    // did it to update BelongsToTenant's comment and docs/02-integration.md in
    // the same commit. Without it, the comment and the docs could quietly
    // become false while the suite stayed green.
    $flow = Flow::create(['name' => 'Welcome', 'status' => 'draft', 'tenant_id' => 'acme']);

    Flow::withoutTenancy()->where('id', $flow->id)->update(['tenant_id' => 'globex']);

    expect(Flow::withoutTenancy()->find($flow->id)->tenant_id)->toBe('globex');
});
