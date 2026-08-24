<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Models\Run;
use Throwable;

class TriggerOccurrenceDispatcher
{
    public function __construct(
        private readonly TriggerActivationRepository $activations,
        private readonly TriggerSourceRegistry $sources,
        private readonly TriggerRunStarter $runs,
    ) {}

    /** @return Run[] */
    public function dispatch(TriggerOccurrence $occurrence): array
    {
        try {
            $source = $this->sources->resolve($occurrence->driver, $occurrence->source);
            $activations = $occurrence->activations
                ?? $this->activations->forDriverSource(
                    $occurrence->driver,
                    $occurrence->source,
                    $occurrence->qualifier,
                );
        } catch (Throwable $e) {
            $this->reportSafely($e);

            return [];
        }

        $started = [];

        foreach ($activations as $activation) {
            try {
                $this->assertCoherent($occurrence, $activation);
                $match = $source->resolve($occurrence, $activation->descriptor);
                $tenantMatch = $this->matchForActivation($match, $activation);

                if ($tenantMatch === null) {
                    continue;
                }

                $started[] = $this->runs->start($activation, $tenantMatch);
            } catch (Throwable $e) {
                $this->reportSafely($e);
            }
        }

        return $started;
    }

    private function assertCoherent(
        TriggerOccurrence $occurrence,
        TriggerActivationSnapshot $activation,
    ): void {
        if ($activation->driver !== $occurrence->driver) {
            throw new InvalidArgumentException(
                "Trigger activation driver [{$activation->driver}] does not match occurrence driver [{$occurrence->driver}]."
            );
        }

        if ($activation->source !== $occurrence->source) {
            throw new InvalidArgumentException(
                "Trigger activation source [{$activation->source}] does not match occurrence source [{$occurrence->source}]."
            );
        }

        if ($activation->qualifier !== $occurrence->qualifier) {
            $activationQualifier = $activation->qualifier ?? 'null';
            $occurrenceQualifier = $occurrence->qualifier ?? 'null';

            throw new InvalidArgumentException(
                "Trigger activation qualifier [{$activationQualifier}] does not match occurrence qualifier [{$occurrenceQualifier}]."
            );
        }
    }

    private function matchForActivation(
        TriggerMatch $match,
        TriggerActivationSnapshot $activation,
    ): ?TriggerTenantMatch {
        $tenantMatches = $match->tenants();

        if ($tenantMatches === []) {
            return null;
        }

        foreach ($tenantMatches as $tenantMatch) {
            if ($tenantMatch->tenantId === $activation->tenantId) {
                return $tenantMatch;
            }
        }

        throw new InvalidArgumentException(
            "Trigger source returned matches, but none for activation tenant [{$activation->tenantId}]."
        );
    }

    private function reportSafely(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // A host reporter must not turn one activation failure into a
            // fan-out failure for every remaining activation.
        }
    }
}
