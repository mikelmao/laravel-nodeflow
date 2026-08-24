<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;
use Nodeflow\Console\SourceText;

/**
 * The registration home every generator writes into.
 *
 * `nodeflow:make-node` has always looked for this exact file with this exact
 * anchor, and until this command existed nothing created it — so every host took
 * the generator's fallback path and pasted a `Nodeflow::register([...])` line
 * instead. That is the story this step ends.
 */
final class ProviderStep implements InstallStep
{
    public const PATH = 'app/Providers/NodeflowServiceProvider.php';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $rootNamespace,
    ) {}

    public function describe(): string
    {
        return 'Provider ('.self::PATH.')';
    }

    private const CLASS_ANCHOR = 'class NodeflowServiceProvider';

    private const BOOT_ANCHOR = 'public function boot(): void';

    /**
     * The registration homes, each with the anchor it is inserted after, the text
     * inserted, and how its presence is recognised.
     *
     * @return array<int, array{anchor: string, needle: string, insert: string}>
     */
    private function homes(): array
    {
        // Homes sharing an anchor are inserted in reverse display order because
        // each splice lands immediately after that anchor. The resulting trigger
        // portion is always drivers, nodes, then sources; executable nodes are
        // registered before that dependency chain.
        return [
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => '->register(...$this->subjectAttributes());',
                'insert' => PHP_EOL.'        app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::TRIGGER_NODE_ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::registerTriggerSources($this->triggerSources);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::registerTriggerSources($this->triggerSources);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::registerTriggerNodes($this->triggerNodes);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::registerTriggerNodes($this->triggerNodes);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::registerTriggerDrivers($this->triggerDrivers);'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::register($this->nodes);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::register($this->nodes);'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
                'insert' => PHP_EOL.'    /** @return \Nodeflow\Schema\SubjectAttribute[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::ATTRIBUTE_ANCHOR
                    .PHP_EOL.'    {'
                    .PHP_EOL.'        return ['
                    .PHP_EOL.'        ];'
                    .PHP_EOL.'    }'.PHP_EOL,
            ],
        ];
    }

    public function check(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return InstallOutcome::Writable;
        }

        $contents = $this->files->get($this->path());

        // Comment-stripped (E22): a host who commented out one of the three
        // boot() registration calls while debugging must be told the truth
        // rather than told they are wired — the array anchors can be genuinely
        // present while nothing actually registers. Anchor counts just below
        // stay on the RAW bytes; only the needle match is stripped.
        $stripped = SourceText::withoutPhpComments($contents);

        if (! $this->hasSafeTopology($contents)) {
            return InstallOutcome::CannotWire;
        }

        $missing = array_filter(
            $this->homes(),
            fn (array $home) => ! str_contains($stripped, $home['needle']),
        );

        if ($missing === []) {
            return $this->isCompleteProvider($contents)
                ? InstallOutcome::AlreadyPresent
                : InstallOutcome::CannotWire;
        }

        // A differently formatted declaration is host code, not an absent
        // home. Inserting our exact anchor alongside it would create a duplicate
        // property/method and can make the provider unloadable. Refuse the
        // automatic upgrade and show the manual shape instead.
        $declarationPatterns = [
            NodeRegistrationWriter::ANCHOR => '/\$nodes\b/',
            NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR => '/\$triggerDrivers\b/',
            NodeRegistrationWriter::TRIGGER_NODE_ANCHOR => '/\$triggerNodes\b/',
            NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR => '/\$triggerSources\b/',
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR => '/\bfunction\s+subjectAttributes\s*\(/',
        ];

        foreach ($missing as $home) {
            $pattern = $declarationPatterns[$home['needle']] ?? null;
            if ($pattern !== null && preg_match($pattern, $stripped) === 1) {
                return InstallOutcome::CannotWire;
            }
        }

        // Every anchor a missing home needs must be present exactly once, or this
        // step cannot prove where the insertion belongs. Refusing beats guessing:
        // an edit that applies cleanly and matches nothing has cost this project
        // time twice already.
        foreach ($missing as $home) {
            if (substr_count($contents, $home['anchor']) !== 1) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::Writable;
    }

    public function apply(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return $this->create();
        }

        if ($this->check() !== InstallOutcome::Writable) {
            return $this->check();
        }

        // Build the complete edit in memory and write once. A later unsafe home
        // can therefore never leave an earlier partial insertion behind.
        $contents = $this->files->get($this->path());
        $original = $contents;

        foreach ($this->homes() as $home) {
            // Same comment-stripped needle match as check(): otherwise a home
            // commented out is read as already there and this step skips it,
            // leaving the commented-out call the only one in the file.
            if (str_contains(SourceText::withoutPhpComments($contents), $home['needle'])) {
                continue;
            }

            $propertyOrder = [
                NodeRegistrationWriter::ANCHOR,
                NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
                NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
                NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
            ];
            $propertyIndex = array_search($home['needle'], $propertyOrder, true);
            if ($propertyIndex !== false) {
                $position = false;
                foreach (array_slice($propertyOrder, $propertyIndex + 1) as $next) {
                    $position = strpos($contents, $next);
                    if ($position !== false) {
                        break;
                    }
                }
                if ($position === false) {
                    $position = strpos($contents, self::BOOT_ANCHOR);
                }
                if ($position === false) {
                    return InstallOutcome::CannotWire;
                }
                $position = strrpos(substr($contents, 0, $position), "\n") + 1;
                $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
                $insert = str_replace("\n", $newline, str_replace("\r\n", "\n", $home['insert']));
                $contents = substr_replace($contents, $insert, $position, 0);
                continue;
            }

            if (substr_count($contents, $home['anchor']) !== 1) {
                return InstallOutcome::CannotWire;
            }

            if ($home['needle'] === NodeRegistrationWriter::ATTRIBUTE_ANCHOR) {
                $position = strrpos($contents, '}');
                if ($position === false) {
                    return InstallOutcome::CannotWire;
                }
                $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
                $insert = str_replace("\n", $newline, str_replace("\r\n", "\n", $home['insert']));
                $contents = substr_replace($contents, $insert, $position, 0);
                continue;
            }

            $anchorPosition = strpos($contents, $home['anchor']);
            if ($anchorPosition === false) {
                return InstallOutcome::CannotWire;
            }

            $position = $anchorPosition + strlen($home['anchor']);

            // Past the anchor line's own opening brace, so the insertion lands
            // inside the class or the method rather than on its signature line.
            $brace = strpos($contents, '{', $position);
            if ($brace === false) {
                return InstallOutcome::CannotWire;
            }
            $position = $brace + 1;

            $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
            $insert = str_replace("\n", $newline, str_replace("\r\n", "\n", $home['insert']));

            $contents = substr_replace($contents, $insert, $position, 0);
        }

        if (! $this->isCompleteProvider($contents)) {
            return InstallOutcome::CannotWire;
        }

        try {
            $writtenBytes = $this->files->put($this->path(), $contents);
            $written = $this->files->exists($this->path()) ? $this->files->get($this->path()) : null;
            if ($writtenBytes !== strlen($contents)
                || $written !== $contents
                || ! $this->isCompleteProvider($written)) {
                $this->files->put($this->path(), $original);

                return InstallOutcome::CannotWire;
            }
        } catch (\Throwable) {
            $this->files->put($this->path(), $original);

            return InstallOutcome::CannotWire;
        }

        return InstallOutcome::Wired;
    }

    public function snippet(): ?string
    {
        if ($this->check() !== InstallOutcome::CannotWire) {
            return null;
        }

        return <<<'PHP'
        // Add these registration homes to your NodeflowServiceProvider, and
        // the calls in boot(). The generators match the property and method
        // lines literally, so keep them exactly as written.

            /** @var class-string[] */
            protected array $nodes = [
            ];

            /** @var class-string[] */
            protected array $triggerDrivers = [
            ];

            /** @var class-string[] */
            protected array $triggerNodes = [
            ];

            /** @var class-string[] */
            protected array $triggerSources = [
            ];

            public function boot(): void
            {
                \Nodeflow\Nodeflow::register($this->nodes);
                \Nodeflow\Nodeflow::registerTriggerDrivers($this->triggerDrivers);
                \Nodeflow\Nodeflow::registerTriggerNodes($this->triggerNodes);
                \Nodeflow\Nodeflow::registerTriggerSources($this->triggerSources);
                app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
            }

            /** @return \Nodeflow\Schema\SubjectAttribute[] */
            protected function subjectAttributes(): array
            {
                return [
                ];
            }
        PHP;
    }

    private function create(): InstallOutcome
    {
        $rendered = strtr($this->stub(), [
            '{{ namespace }}' => rtrim($this->rootNamespace, '\\').'\\Providers',
        ]);

        if (! $this->isCompleteProvider($rendered)) {
            return InstallOutcome::CannotWire;
        }

        $this->files->ensureDirectoryExists(dirname($this->path()));

        try {
            $writtenBytes = $this->files->put($this->path(), $rendered);
            $written = $this->files->exists($this->path()) ? $this->files->get($this->path()) : null;
            if ($writtenBytes !== strlen($rendered)
                || $written !== $rendered
                || ! $this->isCompleteProvider($written)) {
                throw new \RuntimeException('The created provider could not be verified.');
            }
        } catch (\Throwable) {
            try {
                $this->files->delete($this->path());
            } catch (\Throwable) {
                // CannotWire is still the only safe outcome. Filesystem-level
                // recovery failures remain visible to the host on inspection.
            }

            return InstallOutcome::CannotWire;
        }

        return InstallOutcome::Wired;
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }

    /** Host stub overrides, the same convention MakeNodeCommand::resolveStubPath() follows. */
    private function stub(): string
    {
        $custom = $this->basePath.'/stubs/nodeflow-provider.stub';

        return $this->files->get(
            $this->files->exists($custom) ? $custom : __DIR__.'/../../../stubs/nodeflow-provider.stub'
        );
    }

    private function parses(string $contents): bool
    {
        try {
            token_get_all($contents, TOKEN_PARSE);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isCompleteProvider(string $contents): bool
    {
        if (! $this->parses($contents) || ! $this->hasSafeTopology($contents)) {
            return false;
        }

        $stripped = SourceText::withoutPhpComments($contents);
        foreach ($this->homes() as $home) {
            if (substr_count($stripped, $home['needle']) !== 1) {
                return false;
            }
        }

        return ProviderStructureInspector::valid(
            $contents,
            rtrim($this->rootNamespace, '\\').'\\Providers',
        );
    }

    private function hasSafeTopology(string $contents): bool
    {
        $stripped = SourceText::withoutPhpComments($contents);
        $groups = [
            [
                NodeRegistrationWriter::ANCHOR,
                NodeRegistrationWriter::TRIGGER_DRIVER_ANCHOR,
                NodeRegistrationWriter::TRIGGER_NODE_ANCHOR,
                NodeRegistrationWriter::TRIGGER_SOURCE_ANCHOR,
                NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            ],
            [
                'Nodeflow::register($this->nodes);',
                'Nodeflow::registerTriggerDrivers($this->triggerDrivers);',
                'Nodeflow::registerTriggerNodes($this->triggerNodes);',
                'Nodeflow::registerTriggerSources($this->triggerSources);',
                '->register(...$this->subjectAttributes());',
            ],
        ];

        foreach ($groups as $groupIndex => $needles) {
            $haystack = $groupIndex === 0 ? $contents : $stripped;
            $last = -1;
            foreach ($needles as $needle) {
                $count = substr_count($haystack, $needle);
                if ($count > 1) {
                    return false;
                }
                if ($count === 0) {
                    continue;
                }
                $position = strpos($haystack, $needle);
                if ($position === false || $position <= $last) {
                    return false;
                }
                $last = $position;
            }
        }

        return true;
    }
}
