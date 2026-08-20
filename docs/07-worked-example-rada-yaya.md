# 7. Worked example — a flood-alert journey

Everything before this document is domain-neutral. This one builds a complete, real journey to show how
the pieces fit, using the first system nodeflow was designed for.

**The setting.** Three systems: a flood-forecasting service that emits alerts scoped to towns, a
messaging platform that delivers to people across channels, and an FSP-facing web application where
each financial institution's staff build their own journeys. Institutions are tenants — the
`Organization` model. Their customers are the subjects.

**The journey.** An alert fires for a set of towns. For every affected customer of that institution:

1. Send the weather alert.
2. Wait 5 minutes, then send a loan-product marketing message.
3. Wait 1 day; if they have not clicked, send a follow-up.
4. If at any point they confirm interest, stop the follow-up and start the loan-application journey.

A single alert covers towns spanning several institutions, so **one alert produces one run per
institution** — separately versioned, separately cancellable.

## 1. The tenancy resolver

```php
namespace App\Nodeflow;

use App\Models\User;
use Nodeflow\Contracts\TenantResolver;

class OrganizationTenantResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        $id = auth()->user()?->organization_id;

        return $id === null ? null : (string) $id;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        return $subjectType === 'user'
            && User::where('id', $subjectId)->where('organization_id', $tenantId)->exists();
    }
}
```

`currentTenantId()` returns `null` in the queue worker that processes an alert — there is no logged-in
user. That is expected and correct: run creation during fan-out is a system operation, and the package
handles writing a run for a tenant other than the ambient one.

## 2. The subject resolver

```php
class UserSubjectResolver implements SubjectResolver
{
    public function resolve(string $subjectType, array $subjectIds): array
    {
        if ($subjectType !== 'user') {
            return [];
        }

        return User::with('channelAccounts')
            ->whereIn('id', $subjectIds)
            ->get()
            ->keyBy(fn (User $u) => (string) $u->getKey())
            ->all();
    }
}
```

Eager-load what the nodes will touch. This runs once per chunk, so an N+1 here becomes an N+1 per 500
people.

## 3. The trigger

```php
namespace App\Nodeflow\Triggers;

use App\Events\AlertDispatched;
use App\Models\User;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;

class FloodAlertFires extends Trigger
{
    public static function type(): string
    {
        return 'rada.alert_dispatched';
    }

    public static function event(): string
    {
        return AlertDispatched::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('A flood alert fires')
            ->description('Starts when an alert is dispatched for one or more towns.')
            ->fields([
                Field::multiselect('severity')
                    ->label('Only these severities')
                    ->options(['yellow' => 'Yellow', 'orange' => 'Orange', 'red' => 'Red']),
            ]);
    }

    public function resolve(object $event): TriggerMatch
    {
        $match = TriggerMatch::make();

        $byTenant = User::query()
            ->whereIn('town_id', $event->townIds)
            ->get()
            ->groupBy('organization_id');

        foreach ($byTenant as $organizationId => $users) {
            $match->forTenant(
                (string) $organizationId,
                'user',
                $users->pluck('id')->map(fn ($id) => (string) $id)->all(),
            );
        }

        return $match;
    }

    public function idempotencyKey(object $event): ?string
    {
        return 'alert-'.$event->alertId;
    }

    public function matchesConfig(object $event, array $config): bool
    {
        $wanted = $config['severity'] ?? [];

        return $wanted === [] || in_array($event->severity, $wanted, true);
    }
}
```

`idempotencyKey()` matters here: alert dispatch is the kind of thing that gets retried, and a duplicate
run means a second flood warning to the same people.

## 4. The send node

```php
namespace App\Nodeflow\Nodes;

use App\Services\Messaging;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class SendMessage extends Node implements HandlesSubject, HandlesAudience
{
    public static function type(): string
    {
        return 'messaging.send';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send Message')
            ->group('Messaging')
            ->outputs(['sent', 'failed'])
            ->fields([
                Field::select('template')
                    ->label('Message template')
                    ->optionsFrom(MessageTemplateOptions::class)
                    ->required(),
                Field::select('channel')
                    ->options(['sms' => 'SMS', 'whatsapp' => 'WhatsApp'])
                    ->default('sms'),
            ]);
    }

    public function defaultConfig(): array
    {
        return ['channel' => 'sms'];
    }

    // Batch path: one API call per chunk. Preferred when present.
    public function forAudience(AudienceContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->all('sent');
        }

        $result = app(Messaging::class)->sendBatch(
            $context->subjects(),
            $context->config('template'),
            $context->config('channel'),
        );

        return $context->partition([
            'sent'   => $result->deliveredIds,
            'failed' => $result->failedIds,
        ]);
    }

    // Single path: kept as the readable reference, and used by per-subject runs.
    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('sent');
        }

        return app(Messaging::class)->send(
            $context->subject(),
            $context->config('template'),
            $context->config('channel'),
        )
            ? $context->continue('sent')
            : $context->continue('failed');
    }
}
```

Implementing both is worth it for a sending node: `forAudience()` turns 100,000 individual calls into 20
batch calls, and `forSubject()` stays as the version a reader can follow.

`optionsFrom(MessageTemplateOptions::class)` means each institution sees only its own templates in the
editor's dropdown. The class it names has to implement `OptionSource` — the options endpoint refuses
anything else rather than degrading to an empty dropdown:

```php
namespace App\Nodeflow;

use App\Models\MessageTemplate;
use Nodeflow\Schema\OptionSource;

class MessageTemplateOptions implements OptionSource
{
    /** @return array<string, string> value => label */
    public function options(): array
    {
        // Runs inside the editor request, so the tenancy scope already applies:
        // this returns only the current institution's templates.
        return MessageTemplate::orderBy('name')->pluck('name', 'key')->all();
    }
}
```

## 5. The subject attributes

These are the only things a customer's staff can build a condition on:

```php
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

app(SubjectAttributeRegistry::class)->register(
    SubjectAttribute::make('clicked_loan_offer', 'Has clicked the loan offer', 'boolean',
        fn ($user) => $user->loanOfferClickedAt !== null),

    SubjectAttribute::make('confirmed_interest', 'Has confirmed interest', 'boolean',
        fn ($user) => $user->loanInterestConfirmedAt !== null),

    SubjectAttribute::make('town', 'Town', 'text',
        fn ($user) => $user->town->name),
);
```

Get the `type` right — `boolean` makes the string `"false"` compare as false, which is what an author
typing into a text box expects.

## 6. The graph

What the editor will eventually produce, and what you can publish by hand today:

```php
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

$flow = Flow::create([
    'name' => 'Flood alert → loan offer',
    'trigger_type' => 'rada.alert_dispatched',
    'trigger_config' => ['severity' => ['orange', 'red']],
    'status' => 'active',
]);

app(PublishFlow::class)->publish($flow, [
    'start' => 'alert',
    'nodes' => [
        ['id' => 'alert',     'type' => 'messaging.send',  'config' => ['template' => 'flood-alert', 'channel' => 'sms']],
        ['id' => 'wait5m',    'type' => 'core.wait',       'config' => ['duration' => '5 minutes']],
        ['id' => 'offer',     'type' => 'messaging.send',  'config' => ['template' => 'loan-offer', 'channel' => 'sms']],
        ['id' => 'wait1d',    'type' => 'core.wait',       'config' => ['duration' => '1 day']],
        ['id' => 'clicked?',  'type' => 'core.condition',  'config' => ['attribute' => 'clicked_loan_offer', 'operator' => 'is_true', 'value' => null]],
        ['id' => 'followup',  'type' => 'messaging.send',  'config' => ['template' => 'loan-followup', 'channel' => 'sms']],
        ['id' => 'done',      'type' => 'core.exit',       'config' => []],
    ],
    'edges' => [
        ['from' => 'alert',    'output' => 'sent',   'to' => 'wait5m'],
        ['from' => 'alert',    'output' => 'failed', 'to' => 'done'],
        ['from' => 'wait5m',   'output' => 'default','to' => 'offer'],
        ['from' => 'offer',    'output' => 'sent',   'to' => 'wait1d'],
        ['from' => 'offer',    'output' => 'failed', 'to' => 'done'],
        ['from' => 'wait1d',   'output' => 'default','to' => 'clicked?'],
        ['from' => 'clicked?', 'output' => 'yes',    'to' => 'done'],
        ['from' => 'clicked?', 'output' => 'no',     'to' => 'followup'],
        ['from' => 'followup', 'output' => 'sent',   'to' => 'done'],
        ['from' => 'followup', 'output' => 'failed', 'to' => 'done'],
    ],
]);
```

Note every output of every node has an edge. An output with no edge is legal — those subjects simply
complete — but being explicit makes the journey readable.

## 7. Step 4 — cancellation and the hand-off

The remaining requirement: a customer who confirms interest should stop receiving the follow-up and
enter the loan-application journey instead.

```php
namespace App\Listeners;

use App\Events\LoanInterestConfirmed;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

class HandleLoanInterest
{
    public function __construct(
        private SubjectExiter $exiter,
        private StartRun $startRun,
    ) {}

    public function handle(LoanInterestConfirmed $event): void
    {
        $subjectId = (string) $event->userId;
        $tenantId  = (string) $event->organizationId;

        // Remove them from every live alert journey for THEIR tenant.
        // run_subjects has no tenant scope — scope through the run explicitly.
        $liveRuns = Run::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'running', 'waiting'])
            ->whereHas('subjects', fn ($q) => $q
                ->where('subject_type', 'user')
                ->where('subject_id', $subjectId)
                ->where('status', 'active'))
            ->get();

        foreach ($liveRuns as $run) {
            $this->exiter->exit($run, [$subjectId]);
        }

        // Start the loan-application journey for just this person.
        $loanFlow = Flow::withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('trigger_type', 'manual')
            ->where('name', 'Loan application')
            ->firstOrFail();

        $this->startRun->forFlow($loanFlow, 'user', [$subjectId], [
            'correlation_id' => 'loan-interest-'.$event->userId,
        ]);
    }
}
```

Two things this illustrates:

**Cancellation is absence, not interruption.** `exit()` marks the subject `exited`; when the 1-day wait
elapses, they are not in the audience the condition node loads, so no follow-up is sent. If they were
the last active subject, the run wakes early instead of burning the rest of the timer.

**The tenant scoping is your responsibility here.** `run_subjects` carries no global tenant scope, so the
`where('tenant_id', ...)` on the run is what keeps this from reaching across institutions. This is the
single easiest place to write a cross-tenant bug in an integration — the query above is the shape to
copy.

An alternative for step 4 is the `core.start_flow` node inside the graph itself, which hands its
subjects to another flow and (by default) removes them from the current one. Use that when the hand-off
is part of the authored journey rather than a reaction to an external event.

## 8. Testing it before it touches anyone

```php
$run = app(StartRun::class)->forFlow($flow, 'user', ['42'], ['is_test' => true]);
```

Provided `SendMessage` honours `isTest()` — it does, above — this walks the whole journey, exercises the
condition, and sends nothing.

## What is left to build

This journey is expressible and executable today. What is not here yet: the editor that lets an
institution's staff assemble the graph without writing the array above, and any verification that the
interpreter behaves correctly against a real queue worker over a real multi-day wait. Do the latter
before the first live alert.
