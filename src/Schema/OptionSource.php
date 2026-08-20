<?php

namespace Nodeflow\Schema;

/**
 * Supplies a field's options at edit time, scoped to the current tenant.
 *
 * A select whose choices are host data — this FSP's message templates, this
 * organisation's towns — cannot have them baked into the node definition, because
 * the definition is one class shared by every tenant. So the field names a class
 * and the package asks it, inside the request, with the tenancy resolver already
 * in play.
 *
 * An interface rather than a duck-typed `options()` method: a class that does not
 * implement this fails with its own name in the message, where duck typing
 * degrades to an empty option list — indistinguishable to the author from "this
 * tenant has no templates yet".
 */
interface OptionSource
{
    /** @return array<string, string> value => label */
    public function options(): array;
}
