<?php

namespace Nodeflow\Triggers\Webhook;

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Models\Run;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerActivationSnapshot;
use Nodeflow\Triggers\TriggerActivationValidator;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerRunStarter;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Nodeflow\Triggers\TriggerTenantMatch;
use Throwable;

class WebhookTriggerDriver implements TriggerDriver
{
    public function __construct(
        private readonly TriggerSourceRegistry $sources,
        private readonly TriggerActivationValidator $activations,
        private readonly TriggerRunStarter $runs,
    ) {}

    public static function key(): string
    {
        return 'webhook';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        // The webhook entry point is installed explicitly by the host in a later layer.
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the webhook driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The webhook source is not registered.']];
        }

        if (! $source instanceof WebhookTriggerSource) {
            return ['source' => ['The registered source is not a webhook trigger source.']];
        }

        return [];
    }

    public function dispatch(
        TriggerActivationSnapshot $activation,
        WebhookOccurrence $payload,
    ): Run {
        $this->activations->validatePinned($activation);
        $descriptor = new TriggerActivationDescriptor(
            $activation->driver,
            $activation->source,
            $activation->qualifier,
            $activation->descriptor,
        );

        if ($this->validate($descriptor) !== []) {
            throw new WebhookSourceFailure('Webhook activation validation is unavailable.');
        }

        try {
            $source = $this->sources->resolve(self::key(), $activation->source);
        } catch (Throwable) {
            throw new WebhookSourceFailure('Webhook source registration is unavailable.');
        }

        if (! $source instanceof WebhookTriggerSource) {
            throw new WebhookSourceFailure('Webhook source registration is incompatible.');
        }

        try {
            $match = $source->resolve(new TriggerOccurrence(
                driver: self::key(),
                source: $activation->source,
                payload: $payload,
                qualifier: $activation->qualifier,
                activations: [$activation],
            ), $activation->descriptor);
        } catch (WebhookSourceRejected $e) {
            throw $e;
        } catch (Throwable) {
            // Never retain the source exception as `previous`: a host source may
            // have embedded request payload data in its message or context.
            throw new WebhookSourceFailure('Webhook source resolution failed.');
        }

        $matches = $match->tenants();

        if (count($matches) !== 1
            || $matches[0]->tenantId !== $activation->tenantId
            || $matches[0]->subjectIds === []) {
            throw new WebhookSourceRejected('The webhook source must return one non-empty audience for this flow.');
        }

        $resolved = $matches[0];

        try {
            return $this->runs->start($activation, new TriggerTenantMatch(
                tenantId: $resolved->tenantId,
                subjectType: $resolved->subjectType,
                subjectIds: $resolved->subjectIds,
                triggerData: $resolved->triggerData,
                occurrenceId: $payload->deliveryId,
            ));
        } catch (CrossTenantSubjectException) {
            throw new WebhookSourceRejected('The webhook audience is outside the activation tenant boundary.');
        }
    }
}
