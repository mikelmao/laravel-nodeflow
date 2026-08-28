# Writing nodes

> **Experimental:** Nodeflow is pre-release software. Treat every new node as a production side-effect boundary and test it against your application.

Generate a subject node with this complete command:

```bash
php artisan nodeflow:make-node SendMessage --type=app.send_message --cardinality=subject --outputs=sent,failed --group=Messaging --test
```

The command is `nodeflow:make-node {name}`. Its options are:

| Option | Default | Validation and behavior |
| --- | --- | --- |
| `--type=` | Interactive: proposed from the class name; non-interactive: that derived value with a warning | Required after prompting/derivation. It must match lowercase letters and digits in segments separated by `.` or `_`, must not start with reserved `core.`, and must not already resolve to another registered node. Use a stable, domain-owned value such as `app.send_message`. |
| `--cardinality=` | `subject` | Case-insensitive after trimming; only `subject`, `audience`, or `both` is accepted. |
| `--outputs=` | `default` | A comma-separated list; blanks are discarded and an all-blank list becomes `default`. Each name must use lowercase letters, digits, and underscore-separated segments, and each name must be unique. |
| `--group=` | `General` | The editor palette group. Quotes and backslashes are escaped for the generated PHP. |
| `--test` | off | Generates a Pest test alongside the node. |
| `--force`, `-f` | off | Overwrites an existing generated node and its generated test. |

The generator registers the class in the host provider when it can safely locate its registration anchor; otherwise it prints the registration snippet. A type is part of published graphs, so do not rename it when renaming its PHP class. For registration and aliases, see [Registering domain components](../integration/registering-domain-components.md).

## Implement a subject node

This complete node sends one message per subject. `MessageDelivery` is an application service whose `send()` method must make the supplied idempotency key safe to repeat.

**File: `app/Nodeflow/Nodes/SendMessage.php`**

```php
<?php

namespace App\Nodeflow\Nodes;

use App\Services\MessageDelivery;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Throwable;

class SendMessage extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'app.send_message';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send message')
            ->group('Messaging')
            ->description('Send a configured message to each subject.')
            ->outputs(['sent', 'failed'])
            ->fields([
                Field::select('channel')
                    ->label('Channel')
                    ->options(['email' => 'Email', 'sms' => 'SMS'])
                    ->default('email')
                    ->required(),
                Field::text('message')
                    ->label('Message')
                    ->required(),
            ]);
    }

    public function defaultConfig(): array
    {
        return ['channel' => 'email'];
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('sent');
        }

        try {
            app(MessageDelivery::class)->send(
                subject: $context->subject(),
                channel: (string) $context->config('channel'),
                message: (string) $context->config('message'),
                idempotencyKey: hash('sha256', json_encode([
                    $context->runId(),
                    $context->nodeId(),
                    $context->subjectId(),
                ], JSON_THROW_ON_ERROR)),
            );

            return $context->continue('sent');
        } catch (Throwable $exception) {
            report($exception);

            return $context->fail('Message delivery failed.');
        }
    }
}
```

`type()` is the stable graph identifier. `definition()` supplies the palette label, outputs, and fields; those output names are the only labels an outgoing edge may use. `defaultConfig()` supplies initial editor configuration. Register the class with `Nodeflow::register([SendMessage::class])` in the host provider.

## Declare durable activity policy

Every executable node inherits these public properties: `$tries` (default `3`), `$backoff` (default `[1, 2, 5, 10, 15, 30, 60, 120]` seconds), `$timeout` (per-attempt start-to-close seconds, default `null`), and `$nonRetryableErrorTypes` (default `[]`). Nodeflow validates them and freezes their values into the published graph's `runtime.activity` snapshot. A new publication overwrites author metadata with a fresh marked snapshot; an unmarked legacy `runtime.activity` value defaults safely at execution. The interpreter uses the immutable snapshot for the durable `RunNodeActivity`; changing a node class later affects only a newly published version, never an existing run.

This defaulting is an upgrade consideration for legacy published versions without a marked activity snapshot. A node activity scheduled from such a version after the upgrade uses Nodeflow's current defaults—three attempts and the default backoff—where the previous runtime scheduled one attempt. An activity already scheduled durably retains the policy recorded with that durable command. Before upgrading, either drain live legacy runs or confirm that every external effect they may still schedule is idempotent across retries.

Keep overrides public and type-compatible with the base declarations: `int $tries`, `int|array $backoff`, `?int $timeout`, and `array $nonRetryableErrorTypes`. Publication rejects malformed values. A name in `$nonRetryableErrorTypes` cannot have surrounding whitespace; Nodeflow does not trim and reinterpret it. A loaded name must name a `Throwable`; an unresolved optional class name is allowed and is matched by its stored name at runtime. Published snapshots are structurally decoded during replay, so optional-package availability on a worker cannot change a recorded workflow command.

## Choose cardinality deliberately

Implement `HandlesSubject` for single-subject work. `forSubject(SubjectContext $context): NodeResult` is called once per subject, with failures isolated to that subject.

Implement `HandlesAudience` for native batch work. `forAudience(AudienceContext $context): NodeResult` receives a chunk of subject IDs and returns their routing together.

If a node implements both interfaces, `NodeRunner` always prefers `HandlesAudience`: it calls `forAudience()` for every chunk and does not call `forSubject()`. The two paths therefore need identical routing and test-mode semantics before you expose both.

## Use the context surface

The following are the public methods intended for node bodies. Both constructors are public for runtime wiring, but are runtime-owned construction APIs: host and node code must not instantiate either context.

`SubjectContext` exposes these node-body methods:

| Method | Purpose |
| --- | --- |
| `runId(): int` | Run identity for logs and idempotency keys. |
| `correlationId(): ?string` | Host correlation ID, including sub-flow lineage when present. |
| `nodeId(): string` | Current graph node ID. |
| `subject(): mixed` | Resolved subject value, or `null` when it could not be resolved. |
| `subjectId(): string` | Current subject ID. |
| `config(?string $key = null, mixed $default = null): mixed` | All configuration, or one value with a fallback. |
| `isTest(): bool` | Whether externally visible side effects are forbidden. |
| `continue(string $output = 'default'): NodeResult` | Route this subject to an output. |
| `fail(string $message): NodeResult` | Record a failure for this subject. |

`AudienceContext` exposes these node-body methods:

| Method | Purpose |
| --- | --- |
| `runId(): int` | Run identity for logs and idempotency keys. |
| `correlationId(): ?string` | Host correlation ID, including sub-flow lineage when present. |
| `nodeId(): string` | Current graph node ID. |
| `subjectIds(): array` | String IDs in the current audience chunk. |
| `subjectType(): string` | The chunk's subject type. |
| `subjects(): array` | A `subjectId => model` map resolved by the host `SubjectResolver`. |
| `config(?string $key = null, mixed $default = null): mixed` | All configuration, or one value with a fallback. |
| `isTest(): bool` | Whether externally visible side effects are forbidden. |
| `partition(array $outputToSubjectIds): NodeResult` | Route IDs to named outputs. IDs are string-cast. |
| `all(string $output = 'default'): NodeResult` | Route every ID in the chunk to one output. |

Contexts intentionally do not expose the mutable `Run` model. Keep application writes behind narrow services rather than reaching into run persistence.

## Return routing and failures

`NodeResult` has these public helpers and readers:

| Method | Behavior |
| --- | --- |
| `forSubject(string $subjectId, string $output = 'default')` | Route one subject ID to one output. |
| `partition(array $outputToSubjectIds)` | Route each output to a list of string-cast IDs. |
| `failed(string $subjectId, string $message)` | Return no output and one subject failure. |
| `empty()` | Return no outputs or failures; the subjects processed at this node complete their flow. |
| `merge(NodeResult ...$results)` | Concatenate output ID lists by output; on duplicate failure IDs, the first failure wins. |
| `outputs(): array` | Return `array<string, string[]>` of output to IDs. |
| `failures(): array` | Return `subjectId => message`; numeric subject IDs become integer PHP array keys. |
| `subjectCount(): int` | Count IDs across outputs only, excluding failures. |

For subject nodes, an uncaught exception becomes `ClassName: message` on that subject, marks it failed, and the remaining subjects continue. Returning `fail()` has the same per-subject isolation with your chosen message. An exception from an audience node propagates from `NodeRunner`, so it fails the node activity and the durable engine retries that one logical node activity under its published policy. Batch implementations must decide whether to partition recoverable failures or abort the batch.

An audience result may contain only IDs from that call's `subjectIds()`, and each ID may appear at most once across all outputs and failures. Any current chunk ID absent from both outputs and failures completes and leaves the flow, even when another ID has an output. Current runner updates do not constrain returned IDs to the node that just ran, so an extraneous active ID in the same run and subject type can advance or fail a subject at another node. Validate or filter IDs in node code before returning the result.

Every node that sends, charges, or writes to another system must return a no-side-effect test result when `isTest()` is true. Make external work idempotent with a stable key such as run ID, node ID, and subject ID (and, for audience work, a stable per-subject or per-chunk key): an audience-node transport or infrastructure exception retries the whole durable node activity and must not duplicate a completed side effect. A stable business rejection should instead return a `NodeResult` failure; it is a recorded business outcome, not a retry signal. Report provider exceptions server-side and return a safe failure message. Do not pass raw provider messages to `fail()` or let them escape unhandled: they may persist in `last_error` and the run view.

## Node review checklist

- Does the node use a stable, application-owned type and declare every edge output?
- Does it implement at least one cardinality interface and register with `Nodeflow::register()`?
- Does each field have server-side rules appropriate to its value?
- Does test mode avoid every external side effect?
- Are side effects safe when this published node activity retries?
- Does each subject receive an output, a failure, or deliberate completion?
- Does an audience result contain each current chunk ID at most once and no external IDs?
- If it implements both interfaces, do audience and subject paths have identical outcomes?

## Next step

Define safe author-facing configuration in [Node fields](node-fields.md).
