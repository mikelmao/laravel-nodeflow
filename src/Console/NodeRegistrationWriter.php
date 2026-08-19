<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;

/**
 * Appends a node class to the host's NodeflowServiceProvider.
 *
 * Separate from MakeNodeCommand because editing someone else's file is the
 * riskiest thing the generator does and deserves tests that do not involve
 * generating anything. The rule it exists to enforce: assert the anchor is
 * present and unique before writing, and change nothing at all otherwise. An
 * edit that applies cleanly and silently matches nothing has cost this project
 * time twice already.
 */
class NodeRegistrationWriter
{
    public const ANCHOR = 'protected array $nodes = [';

    public function __construct(private Filesystem $files) {}

    public function register(string $providerPath, string $nodeClass): NodeRegistrationOutcome
    {
        if (! $this->files->exists($providerPath)) {
            return NodeRegistrationOutcome::ProviderMissing;
        }

        $contents = $this->files->get($providerPath);
        $entry = '\\'.ltrim($nodeClass, '\\').'::class';

        // Searched without the leading backslash so a provider that lists the class
        // as `App\Nodeflow\Nodes\SendSms::class` is recognised too — the backslash is
        // optional in PHP and only the entries this writer wrote itself carry one.
        // The `::class` suffix is what keeps this from matching a longer class name:
        // `SendSms::class` cannot be a prefix of `SendSmsExtra::class`, because the
        // needle requires `::` immediately after the name. A class imported and
        // written as the bare `SendSms::class` is still not recognised — that needs
        // the file's use statements resolved, which nothing here does — so the cost
        // of missing it stays a duplicate entry under the same registry key.
        if (str_contains($contents, ltrim($entry, '\\'))) {
            return NodeRegistrationOutcome::AlreadyPresent;
        }

        $occurrences = substr_count($contents, self::ANCHOR);

        if ($occurrences === 0) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        if ($occurrences > 1) {
            return NodeRegistrationOutcome::AnchorAmbiguous;
        }

        $position = strpos($contents, self::ANCHOR) + strlen(self::ANCHOR);

        $this->files->put($providerPath, substr_replace(
            $contents,
            PHP_EOL.'        '.$entry.',',
            $position,
            0,
        ));

        return NodeRegistrationOutcome::Appended;
    }
}
