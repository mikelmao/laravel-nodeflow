<?php

namespace Nodeflow\Nodes;

interface HandlesUniformAudience extends HandlesAudience
{
    public function audienceOutput(): string;
}
