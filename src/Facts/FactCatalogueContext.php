<?php

namespace Nodeflow\Facts;

use Nodeflow\Models\Flow;

final readonly class FactCatalogueContext
{
    public function __construct(public Flow $flow) {}
}

