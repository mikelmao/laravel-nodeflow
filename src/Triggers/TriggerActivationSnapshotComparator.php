<?php

namespace Nodeflow\Triggers;

final class TriggerActivationSnapshotComparator
{
    public function sameSnapshot(
        TriggerActivationSnapshot $first,
        TriggerActivationSnapshot $second,
    ): bool {
        return $first->activationId === $second->activationId
            && $this->sameLogicalSnapshot($first, $second);
    }

    public function sameLogicalSnapshot(
        TriggerActivationSnapshot $first,
        TriggerActivationSnapshot $second,
    ): bool {
        return $first->flowId === $second->flowId
            && $first->flowVersionId === $second->flowVersionId
            && $first->tenantId === $second->tenantId
            && $first->driver === $second->driver
            && $first->source === $second->source
            && $first->qualifier === $second->qualifier
            && $first->triggerNodeId === $second->triggerNodeId
            && $this->metadataEquals($first->descriptor, $second->descriptor);
    }

    public function matchesDescriptor(
        TriggerActivationSnapshot $activation,
        TriggerActivationDescriptor $descriptor,
    ): bool {
        return $descriptor->driver === $activation->driver
            && $descriptor->source === $activation->source
            && $descriptor->qualifier === $activation->qualifier
            && $this->metadataEquals($descriptor->metadata, $activation->descriptor);
    }

    private function metadataEquals(array $first, array $second): bool
    {
        return $this->canonicalize($first) === $this->canonicalize($second);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
