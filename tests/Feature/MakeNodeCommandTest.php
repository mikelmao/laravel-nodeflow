<?php

use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-node-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app', 0777, true);
    mkdir($this->root.'/tests', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

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

it('generates a subject node at the conventional path', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('namespace App\Nodeflow\Nodes;')
        ->toContain('class SendSms extends Node implements HandlesSubject')
        ->toContain("return 'yaya.send_sms';")
        ->toContain('public function forSubject(SubjectContext $context): NodeResult');
});

it('produces a class the registry accepts and can resolve', function () {
    // The counterfactual: drop `implements HandlesSubject` from the stub and this
    // fails. NodeRegistry::register() rejects a node implementing neither
    // cardinality interface, which is the whole reason the stub declares one.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendSms');

    expect(app(NodeRegistry::class)->has('yaya.send_sms'))->toBeTrue();
    expect(app(NodeRegistry::class)->resolve('yaya.send_sms'))
        ->toBeInstanceOf(HandlesSubject::class);
});

it('generates an audience node that does not also declare forSubject', function () {
    // The counterfactual: make getStub() ignore --cardinality and this fails on
    // the forSubject assertion, because the subject stub would be rendered.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBatch',
        '--type' => 'yaya.send_batch',
        '--cardinality' => 'audience',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendBatch.php');

    expect($contents)
        ->toContain('class SendBatch extends Node implements HandlesAudience')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult')
        ->not->toContain('forSubject');
});

it('generates a both-cardinality node declaring two interfaces and two methods', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendEither',
        '--type' => 'yaya.send_either',
        '--cardinality' => 'both',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendEither.php');

    expect($contents)
        ->toContain('implements HandlesSubject, HandlesAudience')
        ->toContain('public function forSubject(SubjectContext $context): NodeResult')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult');
});

it('refuses an unknown cardinality without writing a file', function () {
    // The counterfactual: accept any string and this fails, because getStub()
    // would resolve a nonexistent stub path and throw instead of exiting 1.
    $this->artisan('nodeflow:make-node', [
        'name' => 'Broken',
        '--type' => 'yaya.broken',
        '--cardinality' => 'sideways',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Broken.php')->not->toBeFile();
});
