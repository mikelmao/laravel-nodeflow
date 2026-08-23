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

            foreach ($match->tenants() as $tenantMatch) {
                $flows = Flow::withoutTenancy()
                    ->where('tenant_id', $tenantMatch->tenantId)
                    ->where('trigger_type', $trigger::type())
                    ->where('status', 'active')
                    ->whereNotNull('current_version_id')
                    ->get();

                foreach ($flows as $flow) {
                    if (! $trigger->matchesConfig($event, $flow->trigger_config ?? [])) {
                        continue;
                    }

                    // One flow's (or tenant's) failure must not strand every other
                    // matching tenant's alert — the same principle NodeRunner
                    // applies per subject. A stranded participant here is a bank
                    // whose customers never receive a flood warning. Report the
                    // failure through the host's error handler rather than
                    // swallowing it, and keep going.
                    try {
                        $this->startRun->forFlow(
                            $flow,
                            $tenantMatch->subjectType,
                            $tenantMatch->subjectIds,
                            ['idempotency_key' => $trigger->idempotencyKey($event)],
                        );
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }
    }
}
