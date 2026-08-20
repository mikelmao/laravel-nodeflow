<?php

namespace Nodeflow\Http;

use Illuminate\Http\Request;

/**
 * Route-name resolution that survives a host's own name prefix.
 *
 * Nodeflow::routes() is called inside the host's group, so the host may apply a
 * name prefix the package never sees. Every sibling endpoint is registered by
 * that same call, so the prefix can be recovered from the matched route's name
 * and applied to a sibling's canonical name.
 *
 * Shared by both controllers rather than copied: two copies of this would drift,
 * and the failure mode is a run view or an editor that 500s only in hosts that
 * chose a prefix — which no default-configuration test would ever reach.
 */
trait ResolvesRouteNames
{
    /**
     * @param  string  $name  the canonical name of the route being generated
     * @param  string  $own  the canonical name of the route currently matched
     */
    protected function routeName(Request $request, string $name, string $own): string
    {
        $current = $request->route()?->getName();

        if ($current !== null && $current !== $own && str_ends_with($current, $own)) {
            return substr($current, 0, -strlen($own)).$name;
        }

        return $name;
    }
}
