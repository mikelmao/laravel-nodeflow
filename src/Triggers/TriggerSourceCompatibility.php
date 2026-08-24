<?php

namespace Nodeflow\Triggers;

use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Schema\TriggerDefinition;

/**
 * One authority for which allowlisted sources a trigger node can author.
 *
 * Driver equality and the node's public source contract establish semantic
 * compatibility. Definition collision checks establish whether the pair can be
 * safely presented as one flat config. No built-in class knowledge lives here.
 */
final class TriggerSourceCompatibility
{
    public function __construct(
        private readonly TriggerSourceRegistry $sources,
    ) {}

    public function supports(TriggerNode $node, TriggerSource $source): bool
    {
        return $source::driver() === $node->driver() && $node->supportsSource($source);
    }

    public function authorable(
        TriggerNode $node,
        TriggerSource $source,
        TriggerDefinitionContext $definitions,
    ): bool
    {
        if (! $this->supports($node, $source)) {
            return false;
        }

        return $definitions->node($node)->collidingFieldKeys($definitions->source($source)) === [];
    }

    /** @return string[] */
    public function sourceKeys(TriggerNode $node, TriggerDefinitionContext $definitions): array
    {
        return array_values(array_map(
            fn (TriggerSource $source): string => $source::key(),
            array_filter(
                $this->sources->forDriver($node->driver()),
                fn (TriggerSource $source): bool => $this->authorable($node, $source, $definitions),
            ),
        ));
    }

    public function combinedDefinition(
        TriggerNode $node,
        TriggerSource $source,
        TriggerDefinitionContext $definitions,
    ): TriggerDefinition
    {
        return $definitions->node($node)->combinedWith($definitions->source($source));
    }
}
