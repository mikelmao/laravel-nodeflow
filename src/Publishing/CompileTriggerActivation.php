<?php

namespace Nodeflow\Publishing;

use Illuminate\Support\Str;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Throwable;

class CompileTriggerActivation
{
    public function __construct(
        private readonly TriggerNodeRegistry $nodes,
        private readonly TriggerDriverRegistry $drivers,
        private readonly TriggerSourceRegistry $sources,
    ) {}

    public function compile(Flow $flow, FlowVersion $version, Graph $graph): TriggerActivation
    {
        $nodeId = $graph->startNodeId();
        $this->assertPersistedString($nodeId, 'trigger_node_id', 255, null);
        $node = $graph->node($nodeId);

        if ($node === null) {
            throw $this->invalid($nodeId ?: null, null, 'The trigger activation start node does not exist.');
        }

        $type = $node['type'] ?? null;

        if (! is_string($type) || ! $this->nodes->has($type)) {
            throw $this->invalid($nodeId, null, "Trigger node [{$nodeId}] is not registered.");
        }

        try {
            $trigger = $this->nodes->resolve($type);
            $declaredDriver = $trigger->driver();
        } catch (Throwable) {
            throw $this->invalid($nodeId, 'driver', "Trigger node [{$nodeId}] could not resolve its driver.");
        }

        $this->assertPersistedString($declaredDriver, 'driver', 191, $nodeId);

        if (! $this->drivers->has($declaredDriver)) {
            throw $this->invalid(
                $nodeId,
                'driver',
                "Trigger node [{$nodeId}] uses an unregistered driver [{$declaredDriver}].",
            );
        }

        try {
            $descriptor = $trigger->compile($node['config'] ?? []);
        } catch (Throwable) {
            throw $this->invalid(
                $nodeId,
                null,
                "Trigger node [{$nodeId}] could not compile its activation descriptor.",
            );
        }

        if (! $descriptor instanceof TriggerActivationDescriptor) {
            throw $this->invalid($nodeId, null, "Trigger node [{$nodeId}] compiled an invalid activation descriptor.");
        }

        $this->assertPersistedString($descriptor->driver, 'driver', 191, $nodeId);
        $this->assertPersistedString($descriptor->source, 'source', 191, $nodeId);

        if ($descriptor->qualifier !== null) {
            $this->assertPersistedString($descriptor->qualifier, 'qualifier', 191, $nodeId);
        }

        if ($descriptor->driver !== $declaredDriver) {
            throw $this->invalid(
                $nodeId,
                'driver',
                "Trigger node [{$nodeId}] compiled a driver that does not match its registered driver.",
            );
        }

        if (! $this->sources->has($descriptor->driver, $descriptor->source)) {
            throw $this->invalid(
                $nodeId,
                'source',
                "Trigger node [{$nodeId}] selected a source that is not registered for its driver.",
            );
        }

        try {
            $this->sources->resolve($descriptor->driver, $descriptor->source);
            $driverErrors = $this->drivers->resolve($descriptor->driver)->validate($descriptor);
        } catch (Throwable) {
            throw $this->invalid(
                $nodeId,
                null,
                "Trigger node [{$nodeId}] driver validation could not be completed.",
            );
        }

        if ($driverErrors !== []) {
            throw $this->invalidDriverErrors($nodeId, $driverErrors);
        }

        try {
            json_encode($descriptor->metadata, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw $this->invalid(
                $nodeId,
                null,
                "Trigger node [{$nodeId}] compiled metadata that is not JSON-safe.",
            );
        }

        // Query the relationship instead of deleting a possibly preloaded model.
        // This intentionally uses a relationship-builder delete, which bypasses
        // model delete events. Activation replacement lifecycle hooks are not a
        // supported extension point; webhook work must not depend on them.
        $flow->triggerActivation()->delete();

        $activation = TriggerActivation::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'tenant_id' => $flow->tenant_id,
            'driver' => $descriptor->driver,
            'source' => $descriptor->source,
            'qualifier' => $descriptor->qualifier,
            'trigger_node_id' => $nodeId,
            // Routing keys have indexed columns of their own. Only opaque,
            // driver-owned metadata belongs in the JSON snapshot.
            'descriptor' => $descriptor->metadata,
        ]);

        $flow->unsetRelation('triggerActivation');
        $flow->unsetRelation('activation');

        return $activation;
    }

    private function assertPersistedString(
        string $value,
        string $field,
        int $maximum,
        ?string $nodeId,
    ): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw $this->invalid(
                $nodeId,
                $field,
                "The compiled {$field} must contain valid UTF-8.",
            );
        }

        if (trim($value) === '') {
            throw $this->invalid(
                $nodeId,
                $field,
                "The trigger activation compiled an empty {$field} routing key.",
            );
        }

        if (Str::length($value) > $maximum) {
            throw $this->invalid(
                $nodeId,
                $field,
                "The trigger activation compiled a {$field} value longer than {$maximum} characters.",
            );
        }
    }

    private function invalidDriverErrors(string $nodeId, array $fieldErrors): GraphInvalidException
    {
        $errors = [];
        $nodeErrors = [];

        foreach ($fieldErrors as $field => $messages) {
            $field = is_string($field) ? $field : null;
            $messages = is_array($messages) ? $messages : [$messages];

            foreach ($messages as $message) {
                $message = is_string($message) && $message !== ''
                    ? $message
                    : 'The activation descriptor is invalid.';
                $errors[] = "Trigger node [{$nodeId}] driver validation: {$message}";
                $nodeErrors[] = ['node' => $nodeId, 'field' => $field, 'message' => $message];
            }
        }

        if ($errors === []) {
            return $this->invalid($nodeId, null, "Trigger node [{$nodeId}] driver validation failed.");
        }

        return new GraphInvalidException($errors, $nodeErrors);
    }

    private function invalid(?string $nodeId, ?string $field, string $message): GraphInvalidException
    {
        return new GraphInvalidException(
            [$message],
            [['node' => $nodeId, 'field' => $field, 'message' => $message]],
        );
    }
}
