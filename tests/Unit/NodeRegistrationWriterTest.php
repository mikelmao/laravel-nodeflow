<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;

function provider(string $body): string
{
    $path = sys_get_temp_dir().'/nodeflow-provider-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, $body);

    return $path;
}

function providerWithAnchor(string $entries = '        //'): string
{
    return provider(<<<PHP
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array \$nodes = [
    {$entries}
        ];

        public function boot(): void
        {
            Nodeflow::register(\$this->nodes);
        }
    }
    PHP);
}

it('appends the node class inside the nodes array', function () {
    $path = providerWithAnchor();

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))
        ->toContain('\App\Nodeflow\Nodes\SendSms::class,');
});

it('appends after the anchor line, not at the end of the file', function () {
    // The counterfactual: append to the end of the file and this fails. A class
    // constant written after the closing brace is a syntax error, and a plain
    // str_replace on ']' would land in the wrong array.
    $path = providerWithAnchor();

    (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    $contents = file_get_contents($path);

    expect(strpos($contents, 'SendSms::class'))
        ->toBeLessThan(strpos($contents, 'public function boot'));
});

it('does not add a second entry for a class already registered', function () {
    $path = providerWithAnchor('        \App\Nodeflow\Nodes\SendSms::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(1);
});

it('reports a missing provider without creating one', function () {
    $path = sys_get_temp_dir().'/nodeflow-absent-'.bin2hex(random_bytes(6)).'.php';

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::ProviderMissing);
    expect($path)->not->toBeFile();
});

it('refuses to guess when the anchor is absent', function () {
    // This is the trap the project has already paid for: an edit that applies
    // cleanly and changes nothing. The counterfactual: skip the anchor check and
    // this returns Appended while the file is untouched.
    $path = provider("<?php\n\nclass Whatever {}\n");
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses to guess when the anchor is ambiguous', function () {
    $path = provider(<<<'PHP'
    <?php

    class Two
    {
        protected array $nodes = [
        ];

        protected array $nodes = [
        ];
    }
    PHP);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});
