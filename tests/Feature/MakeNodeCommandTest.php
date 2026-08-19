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
