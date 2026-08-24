# Test mode

Test mode creates a durable run that follows the normal Nodeflow execution path while requiring node authors to suppress every externally visible side effect. It is not a sandbox, audience-wide preview, or dry-run projection.

> **Warning:** The runtime does not enforce side-effect suppression. A test run can send email, call an API, write to a third-party service, or charge a payment unless every custom node explicitly prevents it.

## Start an authorized test run

Authorize the flow first, supply `is_test => true`, and use an idempotency key from a namespace that cannot collide with a live start for the same flow version. Generate one operation UUID before the first request and reuse it for retries of that same requested test run.

**File: `app/Http/Controllers/FlowTestRunController.php`**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;

class FlowTestRunController
{
    public function store(Request $request, Flow $flow): JsonResponse
    {
        Gate::authorize('runManually', $flow);

        $data = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_ids' => ['required', 'array'],
            'subject_ids.*' => ['string'],
            'operation_id' => ['required', 'uuid'],
        ]);

        $run = app(StartRun::class)->forFlow(
            flow: $flow,
            subjectType: $data['subject_type'],
            subjectIds: $data['subject_ids'],
            options: [
                // The client creates operation_id once and reuses it on a retry.
                // This test/operator namespace cannot collide with a live key.
                'idempotency_key' => 'test:'.$request->user()->getAuthIdentifier().':'.$data['operation_id'],
                'strategy' => 'cohort',
                'is_test' => true,
            ],
        );

        return response()->json(['id' => $run->id], 201);
    }
}
```

The key is unique within the current flow version, so the same operation ID can be used safely only for retries of that requested test start on that version. The route model binding must remain tenant-scoped, and the application must define `runManually` appropriately. Do not take a tenant ID, flow version ID, or `flow_version_id` from the request. See [Starting runs](../building-automations/starting-runs.md) for the start contract.

## What still runs and persists

`StartRun` still creates the run, materializes its authorized audience, and stores `is_test = true`. After that transaction commits, it starts the engine and records the engine workflow ID on success. The durable package still persists its workflow history and tasks. `FlowInterpreter`, its activities, `NodeRunner`, node routing, subject status changes, node-execution records, waits, signals, and sub-flow starts still execute.

`SubjectContext::isTest()` and `AudienceContext::isTest()` are the signal available to custom nodes. A node must use it before *all* externally visible work, not just before a familiar API call. That includes messages, webhooks, payments, third-party writes, externally observable database mutations, and host-domain events that trigger external handlers.

## Write a safe node

This complete subject node returns its normal route in test mode without invoking its delivery service.

**File: `app/Nodeflow/Nodes/SendReceipt.php`**

```php
<?php

namespace App\Nodeflow\Nodes;

use App\Services\ReceiptDelivery;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class SendReceipt extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'shop.send_receipt';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send receipt')
            ->outputs(['sent']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('sent');
        }

        app(ReceiptDelivery::class)->send(
            subject: $context->subject(),
            idempotencyKey: hash('sha256', json_encode([
                $context->runId(),
                $context->nodeId(),
                $context->subjectId(),
            ], JSON_THROW_ON_ERROR)),
        );

        return $context->continue('sent');
    }
}
```

**Unsafe counterexample — do not copy:**

```php
public function forSubject(SubjectContext $context): NodeResult
{
    app(ReceiptDelivery::class)->send(subject: $context->subject());

    if ($context->isTest()) {
        return $context->continue('sent');
    }

    return $context->continue('sent');
}
```

The check happens after the visible effect, so a test run sends a real receipt. Checking only an email client is also insufficient if the node writes to another service or dispatches an event with external listeners.

## Know the boundaries

Test mode does not replace the supplied audience with a sample subject, hide run records from normal operations, or simulate routing without persistence. It also does not intercept events emitted by a node. Use a dedicated test tenant or test-safe subjects when host code itself has visible behavior.

Webhook, model, and Laravel-event trigger runs are live by default: their source match supplies trigger data and occurrence identity but no `is_test` option, so dispatching a real occurrence can reach real node effects. A child run created by the built-in `core.start_flow` node inherits its parent run's `is_test` value; it still needs every child-flow node to be test-safe.

## Next step

Review every node against the [Writing nodes](../building-automations/writing-nodes.md) test-mode checklist before enabling a manual test-run endpoint.
