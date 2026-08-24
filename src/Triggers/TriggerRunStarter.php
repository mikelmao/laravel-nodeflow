<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\CreateRun;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Models\Run;

class TriggerRunStarter
{
    public function __construct(
        private CreateRun $createRun,
        private TenantResolver $tenants,
        private TriggerActivationValidator $activationValidator,
    ) {}

    public function start(TriggerActivationSnapshot $activation, TriggerTenantMatch $match): Run
    {
        // This public API independently validates even when a dispatcher has
        // already done so. Callers cannot construct a token that bypasses the
        // pinned graph/version authority.
        [$version, $entryNodeId] = $this->activationValidator->validatePinned($activation);

        if ($match->tenantId !== $activation->tenantId) {
            throw new InvalidArgumentException(
                "Trigger match tenant [{$match->tenantId}] does not equal activation tenant [{$activation->tenantId}]."
            );
        }

        $subjectIds = array_values(array_map(
            static fn (mixed $subjectId): string => (string) $subjectId,
            iterator_to_array($match->subjectIds, false),
        ));

        foreach ($subjectIds as $subjectId) {
            if (! $this->tenants->ownsSubject($activation->tenantId, $match->subjectType, $subjectId)) {
                throw new CrossTenantSubjectException($activation->tenantId, $match->subjectType, $subjectId);
            }
        }

        return $this->createRun->forVersion(
            $version,
            $match->subjectType,
            $subjectIds,
            $entryNodeId,
            [
                'started_via' => $activation->driver,
                'trigger_node_id' => $activation->triggerNodeId,
                'trigger_data' => $match->triggerData,
                'idempotency_key' => $this->idempotencyKey($activation, $match->occurrenceId),
            ],
        );
    }

    private function idempotencyKey(TriggerActivationSnapshot $activation, ?string $occurrenceId): ?string
    {
        if ($occurrenceId === null) {
            return null;
        }

        $identity = '';

        foreach ([$activation->driver, $activation->source, $occurrenceId] as $component) {
            $identity .= pack('N', strlen($component)).$component;
        }

        return hash('sha256', $identity);
    }
}
