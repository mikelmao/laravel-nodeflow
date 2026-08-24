<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $activationRoutingCollation = in_array(
            DB::connection()->getDriverName(),
            ['mysql', 'mariadb'],
            true,
        ) ? 'utf8mb4_bin' : null;

        Schema::create('nodeflow_flows', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('name');
            $t->string('status')->default('draft');
            $t->string('reentry_policy')->default('reenter');
            $t->foreignId('current_version_id')->nullable();
            $t->json('draft_graph')->nullable();
            $t->timestamp('draft_updated_at')->nullable();
            // The concurrency token save() and StaleDraftException compare on, not
            // draft_updated_at: a stored timestamp only has second precision
            // (Illuminate\Database\Grammar::getDateFormat()), and a debounced
            // autosave can save several times inside one second. Two saves that
            // close together would mint an identical timestamp and stale-write
            // detection would silently stop detecting. draft_updated_at is kept
            // anyway, because "last saved 3 minutes ago" is worth showing an
            // author — it just is not the token.
            $t->unsignedInteger('draft_revision')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('nodeflow_flow_versions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreignId('flow_id')->constrained('nodeflow_flows')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('graph');
            $t->string('content_hash');
            $t->timestamp('published_at')->nullable();
            $t->string('published_by')->nullable();
            $t->timestamps();
            $t->unique(['flow_id', 'version']);
        });

        Schema::create('nodeflow_trigger_activations', function (Blueprint $t) use ($activationRoutingCollation) {
            $t->id();
            $t->foreignId('flow_id')->unique()->constrained('nodeflow_flows')->cascadeOnDelete();
            $t->foreignId('flow_version_id')->unique()->constrained('nodeflow_flow_versions')->cascadeOnDelete();
            $t->string('tenant_id')->index();
            // Three utf8mb4 columns at 191 characters each total 2,292 bytes,
            // under MySQL's 3,072-byte index limit while leaving stable IDs roomy.
            $driver = $t->string('driver', 191);
            $source = $t->string('source', 191);
            $qualifier = $t->string('qualifier', 191)->nullable();

            // MySQL-family default collations are commonly case-insensitive,
            // which would alias extension-owned routing keys. PostgreSQL and
            // SQLite already compare these strings case-exactly and must never
            // receive a MySQL collation name.
            if ($activationRoutingCollation !== null) {
                $driver->collation('utf8mb4_bin');
                $source->collation('utf8mb4_bin');
                $qualifier->collation('utf8mb4_bin');
            }

            $driver->index();
            $source->index();
            $qualifier->index();
            $t->string('trigger_node_id');
            $t->json('descriptor');
            $t->timestamps();
            $t->index(['driver', 'source', 'qualifier']);
        });

        Schema::create('nodeflow_webhook_endpoints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->unique()->constrained('nodeflow_flows')->cascadeOnDelete();
            $t->string('token')->unique();
            $t->text('signing_secret');
            $t->timestamp('secret_rotated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('nodeflow_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_version_id')->constrained('nodeflow_flow_versions');
            $t->string('tenant_id')->index();
            $t->string('correlation_id')->nullable()->index();
            $t->string('engine_workflow_id')->nullable()->index();
            $t->string('engine_entry_node_id')->nullable();
            $t->string('engine_dispatch_status')->nullable()->index();
            $t->text('engine_dispatch_error')->nullable();
            $t->string('strategy');
            $t->string('status')->default('pending');
            $t->boolean('is_test')->default(false);
            $t->string('idempotency_key')->nullable();
            $t->string('started_via');
            $t->string('trigger_node_id');
            $t->json('trigger_data')->nullable();
            $t->unsignedInteger('steps_taken')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->unique(['flow_version_id', 'idempotency_key']);
        });

        Schema::create('nodeflow_run_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('nodeflow_runs')->cascadeOnDelete();
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('current_node_id')->nullable();
            $t->string('status')->default('active');
            $t->text('last_error')->nullable();
            $t->timestamp('exited_at')->nullable();
            $t->unique(['run_id', 'subject_type', 'subject_id']);
            // `id` is the fourth column deliberately. The run view's drill-down
            // reads `where run_id = ? and current_node_id = ? and status = ?
            // order by id` and pages on a cursor, so with the id in the index
            // that is an ordered range scan. Without it, Postgres and SQLite
            // sort the node's entire population on every page — six figures of
            // it — because only InnoDB carries the primary key in a secondary
            // index implicitly. Folded into this migration rather than added in
            // a new one because nothing is installed anywhere yet.
            $t->index(['run_id', 'current_node_id', 'status', 'id']);
        });

        Schema::create('nodeflow_node_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('nodeflow_runs')->cascadeOnDelete();
            $t->string('node_id');
            $t->string('output')->nullable();
            $t->unsignedInteger('subject_count')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['run_id', 'node_id']);
        });

        Schema::create('nodeflow_templates', function (Blueprint $t) {
            $t->id();
            $t->string('scope');
            $t->string('tenant_id')->nullable()->index();
            $t->string('name');
            $t->text('description')->nullable();
            $t->json('graph');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'nodeflow_templates', 'nodeflow_node_executions', 'nodeflow_run_subjects',
            'nodeflow_runs', 'nodeflow_webhook_endpoints', 'nodeflow_trigger_activations',
            'nodeflow_flow_versions', 'nodeflow_flows',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
