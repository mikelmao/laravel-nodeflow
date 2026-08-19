<?php

namespace Nodeflow\Nodes;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;

interface HandlesAudience
{
    public function forAudience(AudienceContext $context): NodeResult;
}
