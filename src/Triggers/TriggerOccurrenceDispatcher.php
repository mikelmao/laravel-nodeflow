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
        private readonly TriggerActivationSnapshotComparator $snapshots,
    ) {}

    /** @return Run[] */
    public function dispatch(TriggerOccurrence $occurrence): array
    {
        try {
            $source = $this->sources->resolve($occurrence->driver, $occurrence->source);
            $candidates = $occurrence->activations
                ?? $this->activations->forDriverSource(
                    $occurrence->driver,
                    $occurrence->source,
                    $occurrence->qualifier,
                );
            $activations = $this->normalizeCandidates($candidates);
        } catch (Throwable $e) {
            $this->reportSafely($e);

            throw $e;
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

    /**
     * @param  array<mixed>  $candidates
     * @return TriggerActivationSnapshot[]
     */
    private function normalizeCandidates(array $candidates): array
    {
        $normalized = [];
        $byActivationId = [];
        $byLogicalTuple = [];

        foreach ($candidates as $candidate) {
            if (! $candidate instanceof TriggerActivationSnapshot) {
                throw new InvalidArgumentException(
                    'Every trigger occurrence activation candidate must be a trigger activation snapshot.'
                );
            }

            $activationKey = (string) $candidate->activationId;
            $tupleKey = $candidate->flowVersionId
                ."\0".strlen($candidate->triggerNodeId)
                ."\0".$candidate->triggerNodeId;

            if (isset($byActivationId[$activationKey])) {
                if (! $this->snapshots->sameSnapshot($byActivationId[$activationKey], $candidate)) {
                    throw new InvalidArgumentException(
                        "Conflicting trigger activation snapshots share activation ID [{$candidate->activationId}]."
                    );
                }

                continue;
            }

            if (isset($byLogicalTuple[$tupleKey])) {
                $existing = $byLogicalTuple[$tupleKey];

                if (! $this->snapshots->sameLogicalSnapshot($existing, $candidate)) {
                    throw new InvalidArgumentException(
                        'Conflicting trigger activation snapshots share a flow-version and trigger-node tuple.'
                    );
                }

                $byActivationId[$activationKey] = $existing;

                continue;
            }

            $normalized[] = $candidate;
            $byActivationId[$activationKey] = $candidate;
            $byLogicalTuple[$tupleKey] = $candidate;
        }

        return $normalized;
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
