# 3. Writing nodes

A node is one class. This is the surface the whole package exists to make pleasant, so if something
here feels awkward, it is a bug worth reporting.

## Start from the generator

```bash
php artisan nodeflow:make-node SendSms \
    --type=yaya.send_sms \
    --cardinality=subject \
    --outputs='sent, failed' \
    --group=Messaging \
    --test
```

That writes one file, `app/Nodeflow/Nodes/SendSms.php`, and — with `--test` —
`tests/Feature/Nodeflow/SendSmsTest.php`. One class plus one declarative definition is the whole
node; if you find yourself creating a directory, something has gone wrong.

| Option | Meaning |
|---|---|
| `--type` | The stable identifier. Prompted when omitted **and the input is interactive**. Run it non-interactively (CI, `--no-interaction`) without `--type` and the command derives one from the class name instead and **warns** that it did — that derived value is permanent, since published flow versions resolve through it, so pass `--type` explicitly with your domain prefix |
| `--cardinality` | `subject` (default), `audience`, or `both`. See [Cardinality and partitioning](#cardinality-and-partitioning) — the interface is what the runtime dispatches on, so the generator always writes it for you |
| `--outputs` | Comma-separated output names, rendered into `definition()` and into the generated test. Lowercase letters, digits and underscores — they are edge labels in a flow graph as well as PHP string literals — and each name may appear once |
| `--group` | The palette group the editor shows the node under |
| `--test` | Also generate a Pest test asserting the type, the outputs, the cardinality interface, that the registry accepts the class, and that a required field is enforced — plus a TODO reminding you to add a test per output. Leaves an existing test file untouched unless you also pass `--force` |

Registration is explicit, never automatic discovery. The command looks for
`app/Providers/NodeflowServiceProvider.php` containing the line `protected array $nodes = [`
**exactly once**, and appends the new class there when it finds it. There is no `nodeflow:install`
command yet to have created that provider for you, so on a fresh install — or whenever the anchor is
missing or appears more than once — the command instead prints a `Nodeflow::register([...])` snippet
for you to paste in, and says which of those reasons applied. Today, hitting the snippet is the normal
case, not the exception.

It refuses, rather than generating something broken, when the type doesn't match the expected
lowercase-letters/digits/dots/underscores format, uses the reserved `core.` prefix, or collides with a
type already registered by another node — that last check resolves through registry aliases too, and
names the class that already owns the type. It matters because the registry keys by type: two nodes
sharing one would otherwise silently replace each other. An output name outside the same lowercase
format, or repeated, is refused for the same reason: both the node and the generated test would
otherwise fail to parse, or declare an output twice.

A type already owned by *the class being generated* is not a collision — regenerating a node whose
provider has already registered it is the normal case, and telling you to change its type would be the
one thing a live node's type must never do. `--force` overwrites an existing node class (and, combined
with `--test`, an existing test file) instead of refusing.

## A complete node

```php
namespace App\Nodeflow\Nodes;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class SendMessage extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'app.send_message';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send Message')
            ->group('Messaging')
            ->description('Sends a templated message on the chosen channel.')
            ->outputs(['sent', 'failed'])
            ->fields([
                Field::select('template')->label('Template')->optionsFrom(MessageTemplates::class)->required(),
                Field::select('channel')->options(['sms' => 'SMS', 'email' => 'Email'])->default('sms'),
            ]);
    }

    public function defaultConfig(): array
    {
        return ['channel' => 'sms'];
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('sent');
        }

        $ok = app(Messenger::class)->send(
            $context->subject(),
            $context->config('template'),
            $context->config('channel'),
        );

        return $ok ? $context->continue('sent') : $context->continue('failed');
    }
}
```

Register it (see [Integration](02-integration.md#step-3--register-your-domain-surface)) and it appears
in the palette, validates its own config at publish time, and runs.

## The four declarations

**`type(): string`** — a stable identifier, and the only thing stored in a graph. **Never derive it
from the class name.** The registry maps string → class, so you can rename, move or namespace the PHP
class freely and every stored graph keeps working. If you genuinely need to change the *string*,
register an alias:

```php
Nodeflow::nodes()->alias('app.old_send', 'app.send_message');
```

Both `has()` and `resolve()` honour aliases, so the validator and the runtime agree.

**`definition(): NodeDefinition`** — the label, group, description, optional icon, the **outputs** it
can return, and its config **fields**. This single object produces both the JSON an editor renders and
the Laravel validation rules the server enforces at publish. There is no second place to keep in sync.

**`defaultConfig(): array`** — values a newly-dropped node starts with.

**`validate(array $config): array`** — inherited. It runs the rules derived from your fields and
returns Laravel-style errors keyed by field. Override only if you need cross-field validation; call
`parent::validate()` and merge.

## Cardinality and partitioning

**You must implement at least one of `HandlesSubject` or `HandlesAudience`.** A node implementing
neither is rejected at registration and at publish — it used to fail at runtime instead, which was a
bug worth the loud error it now produces.

### `HandlesSubject` — the default choice

```php
public function forSubject(SubjectContext $context): NodeResult
```

Called **once per subject**. The runtime handles chunking (500 at a time by default), iteration, and
per-subject failure isolation. Write single-person code and ignore that a cohort exists.

**The partition is automatic.** Return a different output per subject and the runtime groups them:

```php
public function forSubject(SubjectContext $context): NodeResult
{
    return $context->continue($context->subject()->isEligible() ? 'yes' : 'no');
}
```

Every subject answering `yes` moves down the `yes` edge; every `no` down the `no` edge. You never write
a `groupBy`, and the two groups proceed independently for the rest of the journey. This is the
mechanism that makes the cohort model tolerable to author against.

### `HandlesAudience` — when batching matters

```php
public function forAudience(AudienceContext $context): NodeResult
```

Called **once per chunk** (5,000 by default) with the whole set. Use it when your downstream API is
batch-shaped — sending 100,000 messages in 20 batch calls rather than 100,000 single calls.

```php
public function forAudience(AudienceContext $context): NodeResult
{
    $result = app(Messenger::class)->sendBatch(
        $context->subjects(),                 // subjectId => model
        $context->config('template'),
    );

    return $context->partition([
        'sent'   => $result->deliveredIds,
        'failed' => $result->failedIds,
    ]);
}
```

Or `$context->all('sent')` to send everyone down one output.

**Implement both** when it is worth it: `forAudience()` is preferred when present, so you can have a
batch fast path and keep the per-subject version as the readable reference. If a node implements both,
`forAudience()` is what actually runs.

### What each context gives you

| `SubjectContext` | `AudienceContext` |
|---|---|
| `subject(): mixed` — your resolved model | `subjects(): array` — `subjectId => model` |
| `subjectId(): string` | `subjectIds(): array`, `subjectType(): string` |
| `config(?string $key = null, $default = null)` | same |
| `isTest(): bool` | same |
| `runId(): int`, `correlationId(): ?string`, `nodeId(): string` | same |
| `continue(string $output = 'default')` | `partition(array)`, `all(string $output = 'default')` |
| `fail(string $message)` | — |

The context deliberately does **not** hand you the `Run` model. You get `runId()` and
`correlationId()` for logging and correlation. A node should not be mutating run state.

## Test mode is your obligation

```php
if ($context->isTest()) {
    return $context->continue('sent');
}
```

`isTest()` is true when the run was started with `['is_test' => true]`. **Every node that sends,
charges, or writes to a third party must honour it.** A non-technical author validating a journey has
no other way to avoid messaging real people, and a node that ignores this turns a dry run into a live
send. Nothing enforces it but review — treat it as part of the contract.

## Failure handling

Two ways a subject can fail:

**Return a failure.** `$context->fail('no channel on file')` records that subject as `failed` with the
message, and the rest of the chunk proceeds.

**Throw.** An exception from `forSubject()` is caught per subject, recorded as a failure with the
exception class and message, and the remaining subjects continue. One malformed person cannot kill a
hundred-thousand-subject run.

**`forAudience()` is different: exceptions propagate and fail the run.** A batch failure is not
attributable to one person, so it fails loudly rather than silently marking everyone failed.

## Config fields

Six types: `text`, `number`, `boolean`, `select`, `multiselect`, `duration`.

```php
Field::text('subject_line')->label('Subject')->help('Shown in the inbox')->required(),
Field::number('attempts')->default(3),
Field::boolean('skip_weekends'),
Field::select('channel')->options(['sms' => 'SMS', 'email' => 'Email'])->required(),
Field::multiselect('tags')->options(['vip' => 'VIP', 'new' => 'New']),
Field::duration('delay')->label('Wait for')->required(),
```

Fluent: `label()`, `help()`, `required()`, `default()`, `options()`, `optionsFrom()`. The label is
derived from the key when you omit it (`template_key` → `Template key`).

**`duration` fields are validated at publish** against the same parser the engine uses. `"5 minutes"`,
`"1 day"`, `"2 weeks"` are fine; anything unparseable, zero, or negative is rejected before it can
become a wait that fires immediately. This exists because `1 dya` used to publish cleanly and then send
the day-two message seconds after the first.

**`optionsFrom()` for tenant-specific options.** Pass a class name; the editor resolves it server-side
at edit time, scoped to the current tenant, so each customer sees only their own templates. A field
with a dynamic source deliberately gets no `in:` validation rule, since the valid set is only knowable
per tenant at request time.

**The class you name must implement `Nodeflow\Schema\OptionSource`:**

```php
use Nodeflow\Schema\OptionSource;

class MessageTemplates implements OptionSource
{
    /** @return array<string, string> value => label */
    public function options(): array
    {
        // Runs inside the request, with your tenancy resolver already in play.
        return Template::pluck('name', 'id')->all();
    }
}
```

It is resolved out of the container, so constructor injection works. Naming a class that does not
implement the interface is a **500 from the options endpoint** — deliberately loud, because the
alternative (duck-typing `options()`) degrades to an empty dropdown that looks exactly like a customer
who has no templates yet. A field with `optionsFrom()` set is advertised to the editor as
`dynamic_options: true`, so the sidebar will call that endpoint the first time the field is shown.

## Retries and idempotency

Node bodies run as queued activities. **The retry policy is currently the engine's default of one
attempt**, and `Node::$tries` is not yet wired. There is also no automatic
`(run, node, subject, attempt)` deduplication key.

Practically: **make a sending node idempotent by its own construction** — key on something stable and
check before you send. Do not assume the framework will stop a double-send.

## A checklist before you ship a node

- [ ] `type()` is a stable string, not derived from the class name
- [ ] Implements `HandlesSubject`, `HandlesAudience`, or both
- [ ] Every output the body can return is declared in `outputs()`
- [ ] `isTest()` is honoured on every externally visible side effect
- [ ] Idempotent, or safe to run twice
- [ ] Registered in a service provider's `boot()`
- [ ] A test that would fail if the node's routing logic broke
