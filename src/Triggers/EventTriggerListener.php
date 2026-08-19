<?php

namespace Nodeflow\Triggers;

use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;

class EventTriggerListener
{
    public function __construct(
        private TriggerRegistry $triggers,
        private StartRun $startRun,
    ) {}

    public function handle(object $event): void
    {
        foreach ($this->triggers->forEvent($event::class) as $trigger) {
            $match = $trigger->resolve($event);

            foreach ($match->tenants() as $tenantId => $audience) {
                $flows = Flow::withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->where('trigger_type', $trigger::type())
                    ->where('status', 'active')
                    ->whereNotNull('current_version_id')
                    ->get();

                foreach ($flows as $flow) {
                    if (! $trigger->matchesConfig($event, $flow->trigger_config ?? [])) {
                        continue;
                    }

                    $this->startRun->forFlow(
                        $flow,
                        $audience['subject_type'],
                        $audience['subject_ids'],
                        ['idempotency_key' => $trigger->idempotencyKey($event)],
                    );
                }
            }
        }
    }
}
