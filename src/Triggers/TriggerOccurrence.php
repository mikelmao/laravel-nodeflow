<?php

namespace Nodeflow\Triggers;

final readonly class TriggerOccurrence
{
    /**
     * @param  TriggerActivationSnapshot[]|null  $activations  Trusted, server-side snapshots issued by
     *                                                        TriggerActivationRepository. Never populate this
     *                                                        field from untrusted deserialized input. Supplied
     *                                                        snapshots intentionally pin the version active at
     *                                                        emission time and are not rechecked against the flow's
     *                                                        current activation or current status.
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $source,
        public readonly mixed $payload,
        public readonly ?string $qualifier = null,
        public readonly ?array $activations = null,
    ) {}
}
