<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;

class CheckNodeTypesCommand extends Command
{
    protected $signature = 'nodeflow:check-node-types';

    protected $description = 'Verify executable and trigger registrations required by active flows and live runs.';

    public function handle(
        NodeRegistry $registry,
        TriggerNodeRegistry $triggerNodes,
        TriggerDriverRegistry $drivers,
        TriggerSourceRegistry $sources,
    ): int {
        $missing = CheckNodeTypesResolver::findMissingTypes($registry, $triggerNodes, $drivers, $sources);

        if ($missing !== []) {
            foreach ($missing as $line) {
                $this->error("Nodeflow health check failed: {$line}");
            }

            return self::FAILURE;
        }

        $this->info('All active trigger and live-run component registrations resolve.');

        return self::SUCCESS;
    }
}
