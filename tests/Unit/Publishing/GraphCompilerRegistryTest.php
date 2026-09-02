<?php

use Nodeflow\Publishing\GraphCompiler;
use Nodeflow\Publishing\GraphCompilerContext;
use Nodeflow\Publishing\GraphCompilerRegistry;
use Nodeflow\Models\Flow;
use Nodeflow\Triggers\TriggerDefinitionContext;

function recordingGraphCompiler(string $key, int $priority): GraphCompiler
{
    return new class($key, $priority) implements GraphCompiler
    {
        public function __construct(private string $compilerKey, private int $compilerPriority) {}

        public function key(): string { return $this->compilerKey; }

        public function priority(): int { return $this->compilerPriority; }

        public function compile(GraphCompilerContext $context, array $graph): array
        {
            $graph['compiled'][] = $this->compilerKey;

            return $graph;
        }
    };
}

it('runs graph compilers by priority and stable key', function () {
    $registry = new GraphCompilerRegistry;
    $registry->register(
        recordingGraphCompiler('zulu', 20),
        recordingGraphCompiler('bravo', 10),
        recordingGraphCompiler('alpha', 10),
    );

    $context = new GraphCompilerContext(new Flow(['name' => 'Compiler test']), 0, new TriggerDefinitionContext);

    expect($registry->compile($context, []))->toBe([
        'compiled' => ['alpha', 'bravo', 'zulu'],
    ]);
});

it('rejects duplicate compiler keys', function () {
    $registry = new GraphCompilerRegistry;
    $registry->register(recordingGraphCompiler('facts', 10));

    expect(fn () => $registry->register(recordingGraphCompiler('facts', 20)))
        ->toThrow(InvalidArgumentException::class, 'Duplicate');
});
