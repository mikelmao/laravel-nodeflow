<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodeflow_flows', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('name');
            $t->string('trigger_type');
            $t->json('trigger_config')->nullable();
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
            $t->index(['tenant_id', 'trigger_type', 'status']);
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

        Schema::create('nodeflow_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_version_id')->constrained('nodeflow_flow_versions');
            $t->string('tenant_id')->index();
            $t->string('correlation_id')->nullable()->index();
            $t->string('engine_workflow_id')->nullable()->index();
            $t->string('strategy');
            $t->string('status')->default('pending');
            $t->boolean('is_test')->default(false);
            $t->string('idempotency_key')->nullable();
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
            'nodeflow_runs', 'nodeflow_flow_versions', 'nodeflow_flows',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
