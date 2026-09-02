<?php

namespace Nodeflow\Facts;

interface FactProvider
{
    public function key(): string;

    public function catalogue(FactCatalogueContext $context): FactCatalogue;

    /**
     * Resolve exactly one result for every requested subject ID.
     *
     * @param list<string> $subjectIds
     * @return iterable<FactResolution>
     */
    public function resolve(
        FactResolutionContext $context,
        CompiledFactPredicate $predicate,
        array $subjectIds,
    ): iterable;
}
