<?php

namespace Nodeflow\Nodes;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;

interface HandlesSubject
{
    public function forSubject(SubjectContext $context): NodeResult;
}
