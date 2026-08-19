<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Nodeflow\Nodes\NodeRegistry;

class CheckNodeTypesCommand extends Command
{
    protected $signature = 'nodeflow:check-node-types';

    protected $description = 'Verify every node type referenced by a flow version with live runs still resolves.';

    public function handle(NodeRegistry $registry): int
    {
        $missing = CheckNodeTypesResolver::findMissingTypes($registry);

        if ($missing !== []) {
            foreach ($missing as $line) {
                $this->error("Unresolvable node type: {$line}");
            }

            $this->line('Re-register the node class, or add an alias with NodeRegistry::alias().');

            return self::FAILURE;
        }

        $this->info('All node types referenced by live runs resolve.');

        return self::SUCCESS;
    }
}
