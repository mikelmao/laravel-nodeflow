<?php

use Nodeflow\Console\MakeTriggerSourceCommand;
use Nodeflow\Triggers\Webhook\WebhookSourceRejected;

function triggerDocumentationFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [$root.'/README.md'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/docs/gitbook', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'md') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function triggerDocumentationCorpus(): string
{
    return implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        triggerDocumentationFiles(),
    ));
}

function triggerDocumentationPage(string $relative): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/docs/gitbook/'.$relative);
}

function markdownHeadingAnchors(string $contents): array
{
    preg_match_all('/^#{1,6}\s+(.+?)\s*#*$/m', $contents, $matches);
    $seen = [];
    $anchors = [];

    foreach ($matches[1] as $heading) {
        $heading = preg_replace('/[`*_~]/', '', $heading);
        $anchor = strtolower((string) preg_replace('/[^\pL\pN _-]/u', '', (string) $heading));
        $anchor = preg_replace('/[\s_]+/u', '-', trim($anchor));
        $base = $anchor;
        $occurrence = $seen[$base] ?? 0;
        $seen[$base] = $occurrence + 1;

        if ($occurrence > 0) {
            $anchor .= '-'.$occurrence;
        }

        $anchors[] = $anchor;
    }

    return $anchors;
}

it('documents the complete first-class trigger surface without the removed trigger API', function () {
    $docs = triggerDocumentationCorpus();
    // Build removed names from fragments so the exact repository inventory does
    // not report the regression test that enforces their absence.
    $removed = [
        'trigger'.'_type',
        'trigger'.'_config',
        'Trigger'.'Registry',
        'EventTrigger'.'Listener',
        'extends '.'Trigger',
    ];

    expect($docs)->toContain('core.trigger.webhook')
        ->toContain('core.trigger.model_observer')
        ->toContain('core.trigger.laravel_event')
        ->toContain('Nodeflow::registerTriggerDrivers')
        ->toContain('Nodeflow::registerTriggerNodes')
        ->toContain('Nodeflow::registerTriggerSources')
        ->toContain('X-Nodeflow-Signature')
        ->toContain('timestamp`.`raw request body')
        ->toContain('exactly one trigger node')
        ->toContain('exactly one `started` edge')
        ->toContain('query-builder bulk updates are not observed')
        ->toContain('Unsigned webhooks are not supported')
        ->not->toContain($removed[0])
        ->not->toContain($removed[1])
        ->not->toContain($removed[2])
        ->not->toContain($removed[3])
        ->not->toContain($removed[4]);
});

it('documents the webhook source rejection boundary with the public exception', function () {
    $docs = triggerDocumentationPage('building-automations/writing-triggers.md');
    preg_match('/## Webhook source.*?```php\s*\n(<\?php.*?)(?=\n```)/s', $docs, $snippet);
    token_get_all($snippet[1], TOKEN_PARSE);
    eval(substr($snippet[1], strlen('<?php')));

    $resolve = new ReflectionMethod(App\Nodeflow\Triggers\OrderWebhookSource::class, 'resolve');
    $parameters = $resolve->getParameters();
    $rejection = new WebhookSourceRejected('safe rejection');

    expect(is_a(WebhookSourceRejected::class, RuntimeException::class, true))->toBeTrue()
        ->and($rejection->getMessage())->toBe('safe rejection')
        ->and($parameters)->toHaveCount(2)
        ->and((string) $parameters[0]->getType())->toBe(Nodeflow\Triggers\TriggerOccurrence::class)
        ->and((string) $parameters[1]->getType())->toBe('array')
        ->and((string) $resolve->getReturnType())->toBe(Nodeflow\Triggers\TriggerMatch::class)
        ->and($docs)->toContain('use Nodeflow\\Triggers\\Webhook\\WebhookSourceRejected;')
        ->toContain("throw new WebhookSourceRejected('The webhook payload is incomplete.');")
        ->toContain('Explicit source rejection is a payload-level `422`')
        ->toContain('`InvalidArgumentException` and every other unexpected source exception')
        ->toContain('sanitized `503`')
        ->toContain('never include the raw payload');
});

it('documents the current trigger-aware node type health check', function () {
    $docs = triggerDocumentationPage('operations/health-checks.md');

    expect($docs)->toContain('active flow activation')
        ->toContain('trigger node, driver, and source')
        ->toContain('flow {flowId} version {versionId} node {nodeId}')
        ->toContain('Nodeflow health check failed:')
        ->toContain('All active trigger and live-run component registrations resolve.')
        ->toContain('manual and sub-flow live runs')
        ->toContain('Nodeflow::registerTriggerNodes')
        ->toContain('Nodeflow::registerTriggerDrivers')
        ->toContain('Nodeflow::registerTriggerSources')
        ->toContain("NodeRegistry::alias('old.type', 'current.type')")
        ->not->toContain('All node types referenced by live runs resolve.')
        ->not->toContain('Unresolvable node type: version');
});

it('keeps the quick-start source namespace aligned with the generator', function () {
    $command = app(MakeTriggerSourceCommand::class);
    $namespace = new ReflectionMethod($command, 'getDefaultNamespace');
    $generatedNamespace = $namespace->invoke($command, 'App');
    $docs = triggerDocumentationPage('getting-started/quick-start.md');

    expect($generatedNamespace)->toBe('App\\Nodeflow\\TriggerSources')
        ->and($docs)->toContain('`app/Nodeflow/TriggerSources/OrderPlacedSource.php`')
        ->toContain('`App\\Nodeflow\\TriggerSources`')
        ->and($docs)->toContain('use App\\Nodeflow\\TriggerSources\\OrderPlacedSource;')
        ->not->toContain('use App\\Nodeflow\\Triggers\\OrderPlacedSource;');
});

it('keeps the flood example setup consistent with the trigger-first walkthrough', function () {
    $docs = triggerDocumentationPage('example-application/application-setup.md');

    expect($docs)->toContain('Nodeflow::routes();')
        ->toContain('Continue with [Flood-alert workflow](flood-alert-workflow.md)')
        ->not->toContain('FloodAlertFlowController')
        ->not->toContain('conversion listener')
        ->not->toContain('follow-up wait')
        ->not->toContain('conversion cancellation');
});

it('uses only current source and provider diagnostics throughout current docs', function () {
    $docs = triggerDocumentationCorpus();
    $removed = [
        'matches'.'Config(',
        '`event'.'()`',
        '$'.'triggers arrays',
    ];

    expect($docs)->toContain('eventClass()')
        ->toContain('snapshot()')
        ->toContain('resolve()')
        ->toContain('immutable activation')
        ->toContain('nodeflow:check-node-types')
        ->not->toContain($removed[0])
        ->not->toContain($removed[1])
        ->not->toContain($removed[2]);
});

it('documents trigger authoring responsibilities and generator safety limits accurately', function () {
    $contracts = triggerDocumentationPage('reference/contracts.md');
    $commands = triggerDocumentationPage('reference/artisan-commands.md');

    expect($contracts)->toContain('`AbstractTriggerNode` owns the node-level fields')
        ->toContain('`TriggerDefinitionContext` snapshots')
        ->toContain('`GraphValidator` combines')
        ->toContain('`CompileTriggerActivation` independently')
        ->and($commands)->toContain('`[a-z][a-z0-9._-]*`')
        ->toContain('191')
        ->toContain('255')
        ->toContain('`manual` and `subflow`')
        ->toContain('class, path, registry, or shared graph-catalog collision')
        ->toContain('atomic generation transaction')
        ->toContain('manual registration fallback');
});

it('provides complete host source and extension examples', function () {
    $docs = triggerDocumentationCorpus();

    expect($docs)->toContain('implements WebhookTriggerSource')
        ->toContain('implements ModelObserverTriggerSource')
        ->toContain('implements LaravelEventTriggerSource')
        ->toContain('public static function modelClass(): string')
        ->toContain('public static function eventClass(): string')
        ->toContain('public function snapshot(object $event): LaravelEventOccurrence')
        ->toContain('public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch')
        ->toContain('TriggerMatch::make()->forTenant(')
        ->toContain('final class PartnerWebhookTrigger extends AbstractTriggerNode')
        ->toContain('final class QueueTriggerDriver implements TriggerDriver')
        ->toContain('Nodeflow::registerTriggerDrivers($this->triggerDrivers);')
        ->toContain('Nodeflow::registerTriggerNodes($this->triggerNodes);')
        ->toContain('Nodeflow::registerTriggerSources($this->triggerSources);');
});

it('documents webhook, model, event, run, editor, and unsupported behavior', function () {
    $docs = triggerDocumentationCorpus();

    foreach ([
        'Idempotency-Key',
        '202 Accepted',
        '404',
        '401',
        '413',
        '422',
        '503',
        'created`, `updated`, `deleted`, and `restored',
        'after the outermost database transaction commits',
        'no transaction is open',
        'value-only snapshot',
        'started_via',
        'trigger_node_id',
        'trigger_data',
        'triggerData()',
        'manual and sub-flow starts bypass trigger matching',
        'Trigger nodes are declarative and are never executed',
        'Multiple trigger nodes are not supported',
        'Expression interpolation is not supported',
        'Schedules are not supported',
    ] as $required) {
        expect($docs)->toContain($required);
    }
});

it('keeps local markdown links and fenced blocks valid', function () {
    foreach (triggerDocumentationFiles() as $path) {
        $contents = (string) file_get_contents($path);

        expect(substr_count($contents, '```') % 2, $path.' has an unclosed fenced block')->toBe(0);

        preg_match_all('/(?<!!)\[[^\]]+\]\(([^)]+)\)/', $contents, $matches);

        foreach ($matches[1] as $target) {
            $target = trim($target);

            if (str_starts_with($target, '<') && str_ends_with($target, '>')) {
                $target = substr($target, 1, -1);
            }

            if ($target === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
                continue;
            }

            [$fileTarget, $fragment] = array_pad(explode('#', $target, 2), 2, null);
            $destination = $fileTarget === ''
                ? $path
                : dirname($path).'/'.rawurldecode($fileTarget);

            expect(is_file($destination), "{$path} links to missing {$target}")->toBeTrue();

            if ($fragment !== null && $fragment !== '') {
                expect(markdownHeadingAnchors((string) file_get_contents($destination)))
                    ->toContain(rawurldecode($fragment));
            }
        }
    }
});

it('keeps complete PHP documentation examples parseable', function () {
    $root = dirname(__DIR__, 2);

    foreach ([
        'docs/gitbook/getting-started/quick-start.md',
        'docs/gitbook/building-automations/writing-triggers.md',
        'docs/gitbook/integration/registering-domain-components.md',
        'docs/gitbook/example-application/flood-alert-workflow.md',
        'docs/gitbook/example-application/testing-the-workflow.md',
    ] as $relative) {
        $contents = (string) file_get_contents($root.'/'.$relative);
        preg_match_all('/```php\s*\n(<\?php.*?)(?=\n```)/s', $contents, $matches);
        expect($matches[1], "{$relative} has no complete PHP example")->not->toBeEmpty();

        foreach ($matches[1] as $index => $code) {
            try {
                expect(token_get_all($code, TOKEN_PARSE))->not->toBeEmpty();
            } catch (ParseError $e) {
                test()->fail("{$relative} PHP block ".($index + 1)." does not parse: {$e->getMessage()}");
            }
        }
    }
});
