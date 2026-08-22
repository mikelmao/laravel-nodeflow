<?php

use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

/**
 * The rendered SubjectAttribute::make(...) expression, lifted back out of the
 * provider so it can be evaluated rather than merely string-matched.
 *
 * Greedy `.*` with the `s` modifier, not `.*?`: the rendered entry spans three
 * lines (the make() call, a // TODO, then the closure), and a non-greedy match
 * would stop at the first `)` — which is the closure's own parameter list — and
 * hand back an expression that does not parse.
 */
function renderedEntryFrom(string $providerPath): string
{
    preg_match(
        '/(\\\\Nodeflow\\\\Schema\\\\SubjectAttribute::make\(.*\))\s*,/s',
        file_get_contents($providerPath),
        $matches,
    );

    return $matches[1] ?? '';
}

function attributeManualRegistrationSnippet(string $output): string
{
    $lines = preg_split('/\R/', $output) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if (str_contains($line, 'app(') && str_contains($line, 'SubjectAttributeRegistry::class')) {
            $start = $index;

            break;
        }
    }

    if ($start === null) {
        return '';
    }

    for ($end = $start; $end < count($lines); $end++) {
        if (trim($lines[$end]) === ');') {
            return implode(PHP_EOL, array_slice($lines, $start, $end - $start + 1));
        }
    }

    return '';
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-attr-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app/Providers', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->providerPath = $this->root.'/app/Providers/NodeflowServiceProvider.php';

    file_put_contents($this->providerPath, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        /** @return \Nodeflow\Schema\SubjectAttribute[] */
        protected function subjectAttributes(): array
        {
            return [
            ];
        }
    }
    PHP);

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

it('appends a fully qualified attribute the provider can use without an import', function () {
    // Counterfactual: render the short name `SubjectAttribute::make(...)` and this
    // fails in every provider whose use block lacks the import — which this
    // command never edits, because a use-block insertion has no anchor it can
    // prove.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'clicked_offer',
        '--label' => 'Has clicked the offer',
        '--type' => 'boolean',
    ])->assertExitCode(0);

    expect(file_get_contents($this->providerPath))
        ->toContain("\Nodeflow\Schema\SubjectAttribute::make('clicked_offer', 'Has clicked the offer', 'boolean',");
});

it('appends an entry the provider parses and that produces a usable attribute', function () {
    // The require-and-execute half: the rendered line must actually construct a
    // SubjectAttribute the registry accepts, not merely look right.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'plan',
        '--label' => 'Plan',
        '--type' => 'text',
    ])->assertExitCode(0);

    expectParseablePhp($this->providerPath);

    // Evaluate the rendered expression rather than trusting the string. This is
    // what catches a rename of SubjectAttribute::make().
    $attribute = eval('return '.renderedEntryFrom($this->providerPath).';');

    expect($attribute)->toBeInstanceOf(SubjectAttribute::class);
    expect($attribute->key)->toBe('plan');
    expect($attribute->label)->toBe('Plan');
    expect($attribute->type)->toBe('text');

    // The registry must accept it, and options() must show the label — that is
    // what a flow author actually sees in the condition sidebar.
    $registry = new SubjectAttributeRegistry;
    $registry->register($attribute);

    expect($registry->has('plan'))->toBeTrue();
    expect($registry->options())->toBe(['plan' => 'Plan']);
});

it('defaults the label from the key', function () {
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'confirmed_interest'])
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toContain("'Confirmed interest'");
});

it('recognises an existing key and appends nothing', function () {
    // Counterfactual: match on the whole rendered line and this fails — a re-run
    // with a different label appends a second entry under one key, and
    // SubjectAttributeRegistry keys by attribute key, so the second silently
    // replaces the first.
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan', '--label' => 'Plan'])
        ->assertExitCode(0);

    $before = file_get_contents($this->providerPath);

    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan', '--label' => 'Different'])
        ->expectsOutputToContain('Already')
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toBe($before);
});

it('rejects a type the registry cannot compare', function () {
    // Counterfactual: accept any --type and this fails. A core.condition coerces
    // comparisons by this string, so a fourth value produces an attribute whose
    // comparisons behave arbitrarily at runtime, in a published graph.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'joined_at',
        '--type' => 'datetime',
    ])->assertExitCode(1);

    expect(file_get_contents($this->providerPath))->not->toContain('joined_at');
});

it('rejects a key a published graph could not resolve', function () {
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'Clicked Offer'])
        ->assertExitCode(1);

    expect(file_get_contents($this->providerPath))->not->toContain('Clicked Offer');
});

it('prints the line and exits zero when there is no provider', function () {
    unlink($this->providerPath);

    $exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:make-subject-attribute', [
        'key' => 'manual_plan',
    ]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('SubjectAttributeRegistry');

    $snippet = attributeManualRegistrationSnippet($output);
    expect($snippet)->not->toBe('');

    $probePath = $this->root.'/app/Providers/ManualAttributeRegistrationProbe.php';
    file_put_contents(
        $probePath,
        "<?php\n\nnamespace App\\Providers;\n\n"
        ."final class ManualAttributeRegistrationProbe\n{\n"
        ."    public static function run(): void\n    {\n"
        .$snippet."\n    }\n}\n",
    );

    expectParseablePhp($probePath);

    require $probePath;

    App\Providers\ManualAttributeRegistrationProbe::run();

    expect(app(SubjectAttributeRegistry::class)->has('manual_plan'))->toBeTrue();
});

it('prints the line when the method anchor is missing', function () {
    file_put_contents($this->providerPath, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
    }
    PHP);

    $before = file_get_contents($this->providerPath);

    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan'])
        ->expectsOutputToContain('subjectAttributes')
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toBe($before);
});
