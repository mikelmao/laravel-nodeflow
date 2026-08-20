<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Nodeflow\Models\Flow;

class FlowPolicy extends DelegatesToGate
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->decide('nodeflow.viewAny', $user);
    }

    /**
     * Viewing one flow maps to the same gate as listing them. The spec names
     * four gates, and a fifth invented here would be a gate no host knows to
     * define — which, under default deny, reads as the package being broken.
     */
    public function view(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.viewAny', $user, $flow);
    }

    public function update(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.update', $user, $flow);
    }

    public function publish(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.publish', $user, $flow);
    }

    /**
     * On the flow, not the run: you manually start a flow, and the run is the
     * result. A RunPolicy method would need a Run that does not exist yet at
     * the moment the decision is made.
     */
    public function runManually(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.runManually', $user, $flow);
    }
}
