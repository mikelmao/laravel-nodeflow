<?php

use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactCatalogueContext;
use Nodeflow\Facts\FactProvider;
use Nodeflow\Facts\FactProviderRegistry;
use Nodeflow\Facts\FactResolution;
use Nodeflow\Facts\FactResolutionContext;

function factsTestProvider(string $key): FactProvider
{
    return new class($key) implements FactProvider
    {
        public function __construct(private readonly string $providerKey) {}

        public function key(): string
        {
            return $this->providerKey;
        }

        public function catalogue(FactCatalogueContext $context): FactCatalogue
        {
            return new FactCatalogue($this->providerKey, 'revision', []);
        }

        public function resolve(
            FactResolutionContext $context,
            CompiledFactPredicate $predicate,
            array $subjectIds,
        ): iterable {
            return [];
        }
    };
}

it('registers providers by stable key in deterministic order', function () {
    $alpha = factsTestProvider('alpha');
    $zulu = factsTestProvider('zulu');
    $registry = new FactProviderRegistry;

    $registry->register($zulu, $alpha);

    expect($registry->get('alpha'))->toBe($alpha)
        ->and(array_keys($registry->all()))->toBe(['alpha', 'zulu']);
});

it('rejects duplicate and unknown providers', function () {
    $registry = new FactProviderRegistry;
    $registry->register(factsTestProvider('crm'));

    expect(fn () => $registry->register(factsTestProvider('crm')))
        ->toThrow(InvalidArgumentException::class, 'Duplicate')
        ->and(fn () => $registry->get('missing'))
        ->toThrow(InvalidArgumentException::class, 'not registered');
});

it('represents present and missing provider values without ambiguity', function () {
    expect(FactResolution::present('user-1', 'agriculture')->toArray())->toBe([
        'subject_id' => 'user-1',
        'value' => 'agriculture',
        'missing' => false,
    ])->and(FactResolution::missing('user-2')->toArray())->toBe([
        'subject_id' => 'user-2',
        'value' => null,
        'missing' => true,
    ]);
});

