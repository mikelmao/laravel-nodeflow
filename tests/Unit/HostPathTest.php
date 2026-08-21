<?php

use Nodeflow\Console\HostPath;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/nodeflow-hostpath-'.bin2hex(random_bytes(6));
    mkdir($this->base.'/app/Nodeflow/Nodes', 0777, true);
    mkdir($this->base.'/packages', 0777, true);
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->base));
    exec('rm -rf '.escapeshellarg($this->base.'-evil'));
});

it('splits a path into segments, dropping empties and dots but keeping dot-dot', function () {
    // Counterfactual: filter out '..' as well (which TsconfigPathsStep's sibling
    // helper deliberately does NOT) and the third expectation fails — callers
    // could no longer refuse a path that climbs out.
    expect(HostPath::segments('/a//b/./c'))->toBe(['a', 'b', 'c']);
    expect(HostPath::segments('a/b/'))->toBe(['a', 'b']);
    expect(HostPath::segments('a/../b'))->toBe(['a', '..', 'b']);
});

it('does not treat a sibling directory with a shared prefix as inside the root', function () {
    // THE bug this class exists to prevent, and the fifth appearance of its
    // class in this codebase. Counterfactual: implement contains() as
    // str_starts_with($candidate, $this->root) and this passes the wrong way.
    mkdir($this->base.'-evil/app', 0777, true);
    touch($this->base.'-evil/app/Foo.php');

    expect(HostPath::root($this->base)->contains($this->base.'-evil/app/Foo.php'))->toBeFalse();
});

it('treats a path under the root as inside it', function () {
    touch($this->base.'/app/Nodeflow/Nodes/SendMessage.php');

    expect(HostPath::root($this->base)->contains($this->base.'/app/Nodeflow/Nodes/SendMessage.php'))->toBeTrue();
});

it('refuses a path that climbs out of the root', function () {
    // R12/R13's shape: ltrim($v, './') collapsed '../vendor/...' into a match.
    expect(HostPath::root($this->base)->contains($this->base.'/../elsewhere/Foo.php'))->toBeFalse();
});

it('refuses a symlink inside the root whose target escapes it', function () {
    // E51. Counterfactual: compare raw segments without resolving, and this
    // passes as "contained" while a scaffold write lands outside the repository.
    $outside = sys_get_temp_dir().'/nodeflow-hostpath-outside-'.bin2hex(random_bytes(6));
    mkdir($outside, 0777, true);
    symlink($outside, $this->base.'/packages/escape');

    expect(HostPath::root($this->base)->contains($this->base.'/packages/escape/pkg'))->toBeFalse();

    exec('rm -rf '.escapeshellarg($outside));
});

it('counts relative depth without stripping a repeated inner segment', function () {
    // R15: str_replace($basePath, '', $entry) strips the basePath's text WHEREVER
    // it occurs. Build a directory that repeats the project's own last segment.
    $project = sys_get_temp_dir().'/nodeflow-hostpath-project-'.bin2hex(random_bytes(6));
    mkdir($project.'/resources/'.basename($project).'/css', 0777, true);

    expect(HostPath::root($project)->relativeDepth($project.'/resources/'.basename($project).'/css'))
        ->toBe(3);

    exec('rm -rf '.escapeshellarg($project));
});

it('resolves a relative path inside the root and refuses one that escapes', function () {
    $host = HostPath::root($this->base);

    expect($host->resolveWithin('packages/acme/sms'))->toBe($this->base.'/packages/acme/sms');
    expect(fn () => $host->resolveWithin('../outside'))
        ->toThrow(InvalidArgumentException::class);
});
