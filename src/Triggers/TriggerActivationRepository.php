<?php

namespace Nodeflow\Triggers;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Models\TriggerActivation;

class TriggerActivationRepository
{
    /** @return TriggerActivationSnapshot[] */
    public function forDriverSource(string $driver, string $source, ?string $qualifier = null): array
    {
        $query = $this->activeActivationQuery()
            ->where('nodeflow_trigger_activations.driver', $driver)
            ->where('nodeflow_trigger_activations.source', $source);

        if ($qualifier === null) {
            $query->whereNull('nodeflow_trigger_activations.qualifier');
        } else {
            $query->where('nodeflow_trigger_activations.qualifier', $qualifier);
        }

        return $query->get()
            ->map(fn (TriggerActivation $activation) => $this->snapshot($activation))
            ->all();
    }

    public function forWebhookToken(string $token): ?TriggerActivationSnapshot
    {
        $activation = $this->activeActivationQuery()
            ->join(
                'nodeflow_webhook_endpoints',
                'nodeflow_webhook_endpoints.flow_id',
                '=',
                'nodeflow_trigger_activations.flow_id',
            )
            ->where('nodeflow_trigger_activations.driver', 'webhook')
            ->where('nodeflow_webhook_endpoints.token', $token)
            ->first();

        return $activation === null ? null : $this->snapshot($activation);
    }

    private function activeActivationQuery(): Builder
    {
        // Trigger fan-out is intentionally a tenant-neutral system read. Tenant
        // isolation comes from the event/source match downstream; an ambient
        // request tenant must neither hide another tenant's activation nor widen
        // this query beyond active parent flows.
        return TriggerActivation::withoutTenancy()
            ->select('nodeflow_trigger_activations.*')
            ->join(
                'nodeflow_flows as activation_flows',
                'activation_flows.id',
                '=',
                'nodeflow_trigger_activations.flow_id',
            )
            ->where('activation_flows.status', 'active')
            ->orderBy('nodeflow_trigger_activations.id');
    }

    private function snapshot(TriggerActivation $activation): TriggerActivationSnapshot
    {
        return new TriggerActivationSnapshot(
            activationId: (int) $activation->id,
            flowId: (int) $activation->flow_id,
            flowVersionId: (int) $activation->flow_version_id,
            tenantId: (string) $activation->tenant_id,
            driver: (string) $activation->driver,
            source: (string) $activation->source,
            qualifier: $activation->qualifier === null ? null : (string) $activation->qualifier,
            triggerNodeId: (string) $activation->trigger_node_id,
            descriptor: $activation->descriptor,
        );
    }
}
