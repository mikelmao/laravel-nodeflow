<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Nodeflow\Models\Run;

class RunPolicy extends DelegatesToGate
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->decide('nodeflow.viewAny', $user);
    }

    public function view(?Authenticatable $user, Run $run): bool
    {
        return $this->decide('nodeflow.viewAny', $user, $run);
    }
}
