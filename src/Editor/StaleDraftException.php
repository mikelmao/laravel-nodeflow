<?php

namespace Nodeflow\Editor;

use RuntimeException;

/**
 * Two authors edited one flow and this save lost the race.
 *
 * Carries the winning draft, because a conflict the client cannot see is a
 * conflict it can only resolve by discarding someone's work. The editor shows
 * "someone else edited this" and has the newer graph in hand to offer.
 *
 * The token here is a revision counter, not draft_updated_at, and it is what
 * the caller must report back on the next save. A timestamp only has
 * second-precision once it round-trips through the database, so a debounced
 * autosave that fires twice in the same second would mint two saves carrying
 * an identical "before" value — collapsing exactly the case this exception
 * exists to catch.
 */
class StaleDraftException extends RuntimeException
{
    public function __construct(
        private array $graph,
        private int $revision,
    ) {
        parent::__construct(
            'This flow\'s draft changed since it was loaded. The save was refused rather than '
            .'overwriting the newer draft.'
        );
    }

    public function graph(): array
    {
        return $this->graph;
    }

    public function revision(): int
    {
        return $this->revision;
    }
}
