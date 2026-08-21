<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;

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
        private NodeRegistrationWriter $writer,
    ) {}

    public function describe(): string
    {
        return 'Provider ('.self::PATH.')';
    }

    private const CLASS_ANCHOR = 'class NodeflowServiceProvider';

    private const BOOT_ANCHOR = 'public function boot(): void';

    /**
     * The three homes, each with the anchor it is inserted after, the text
     * inserted, and how its presence is recognised.
     *
     * @return array<int, array{anchor: string, needle: string, insert: string}>
     */
    private function homes(): array
    {
        return [
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::TRIGGER_ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::TRIGGER_ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
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
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::register($this->nodes);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::register($this->nodes);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => '->register(...$this->triggers);',
                'insert' => PHP_EOL.'        app(\Nodeflow\Triggers\TriggerRegistry::class)->register(...$this->triggers);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => '->register(...$this->subjectAttributes());',
                'insert' => PHP_EOL.'        app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());'.PHP_EOL,
            ],
        ];
    }

    public function check(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return InstallOutcome::Writable;
        }

        $contents = $this->files->get($this->path());

        $missing = array_filter(
            $this->homes(),
            fn (array $home) => ! str_contains($contents, $home['needle']),
        );

        if ($missing === []) {
            return InstallOutcome::AlreadyPresent;
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

        // Re-read between insertions rather than batching: each insertion shifts
        // every later offset, and each one asserts its own anchor against the file
        // as it now stands.
        foreach ($this->homes() as $home) {
            $contents = $this->files->get($this->path());

            if (str_contains($contents, $home['needle'])) {
                continue;
            }

            if (substr_count($contents, $home['anchor']) !== 1) {
                return InstallOutcome::CannotWire;
            }

            $position = strpos($contents, $home['anchor']) + strlen($home['anchor']);

            // Past the anchor line's own opening brace, so the insertion lands
            // inside the class or the method rather than on its signature line.
            $position = strpos($contents, '{', $position) + 1;

            $this->files->put($this->path(), substr_replace($contents, $home['insert'], $position, 0));
        }

        return $this->check() === InstallOutcome::AlreadyPresent
            ? InstallOutcome::Wired
            : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        if ($this->check() !== InstallOutcome::CannotWire) {
            return null;
        }

        return <<<'PHP'
        // Add these three registration homes to your NodeflowServiceProvider, and
        // the three calls in boot(). The generators match the property and method
        // lines literally, so keep them exactly as written.

            /** @var class-string[] */
            protected array $nodes = [
            ];

            /** @var class-string[] */
            protected array $triggers = [
            ];

            public function boot(): void
            {
                \Nodeflow\Nodeflow::register($this->nodes);
                app(\Nodeflow\Triggers\TriggerRegistry::class)->register(...$this->triggers);
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
        $this->files->ensureDirectoryExists(dirname($this->path()));

        $this->files->put($this->path(), strtr($this->stub(), [
            '{{ namespace }}' => rtrim($this->rootNamespace, '\\').'\\Providers',
        ]));

        // E11: re-read and prove the anchors are there. A stub edited past
        // recognition would otherwise ship a provider no generator can write to,
        // and nothing would say so until a host ran make-node and got a paste
        // instruction it could not explain.
        $written = $this->files->get($this->path());

        foreach ([
            NodeRegistrationWriter::ANCHOR,
            NodeRegistrationWriter::TRIGGER_ANCHOR,
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        ] as $anchor) {
            if (substr_count($written, $anchor) !== 1) {
                return InstallOutcome::CannotWire;
            }
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
        $custom = $this->basePath.'/stubs/provider.stub';

        return $this->files->get(
            $this->files->exists($custom) ? $custom : __DIR__.'/../../../stubs/provider.stub'
        );
    }
}
