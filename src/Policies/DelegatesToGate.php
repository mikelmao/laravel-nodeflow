<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Every package policy decision is the host's to make.
 *
 * The package stores an opaque tenant_id and knows nothing about users, roles
 * or plans, so it cannot answer "may this person publish this flow". It asks,
 * via a named gate the host defines — and denies when the host has not defined
 * one, because the alternative is a package that ships open by default and
 * relies on every integrator noticing.
 *
 * A host wanting finer control replaces the policy class outright; these exist
 * so that the common case is one Gate::define() per ability.
 */
abstract class DelegatesToGate
{
    protected function decide(string $gate, ?Authenticatable $user, mixed $model = null): bool
    {
        if (! Gate::has($gate)) {
            return false;
        }

        return Gate::forUser($user)->allows($gate, $model === null ? [] : [$model]);
    }
}
