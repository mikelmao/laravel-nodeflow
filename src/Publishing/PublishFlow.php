<?php

namespace Nodeflow\Publishing;

use Illuminate\Support\Facades\DB;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Triggers\TriggerDefinitionContext;
use Nodeflow\Triggers\Webhook\WebhookCredentials;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;

class PublishFlow
{
    public function __construct(
        private GraphValidator $validator,
        private CompileTriggerActivation $compileActivation,
        private WebhookCredentials $webhookCredentials,
    ) {}

    public function publish(
        Flow $flow,
        array $graph,
        ?string $publishedBy = null,
        ?int $expectedDraftRevision = null,
    ): PublishResult
    {
        $expectedDraftRevision ??= (int) ($flow->draft_revision ?? 0);
        $compiledGraph = Graph::fromArray($graph);
        $definitions = new TriggerDefinitionContext;
        $result = $this->validator->validate($compiledGraph, $definitions);

        if (! $result->passes()) {
            throw new GraphInvalidException($result->errors(), $result->nodeErrors());
        }

        try {
            $encodedGraph = json_encode($graph, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $start = $graph['start'] ?? null;

            if (is_string($start) && preg_match('//u', $start) !== 1) {
                $message = 'The compiled trigger_node_id must contain valid UTF-8.';

                throw new GraphInvalidException(
                    [$message],
                    [['node' => null, 'field' => 'trigger_node_id', 'message' => $message]],
                );
            }

            throw new GraphInvalidException(['The flow graph contains values that cannot be published safely.']);
        }

        [$publishResult, $publishedFlow] = DB::transaction(function () use (
            $flow,
            $graph,
            $encodedGraph,
            $publishedBy,
            $compiledGraph,
            $expectedDraftRevision,
            $definitions,
        ) {
            // This is an identity reload, not an authorization read: the caller
            // reached $flow through a scoped route/query already. Locking the
            // trusted row serializes version numbering and closes the window in
            // which an autosave could be acknowledged and then silently cleared.
            $lockedFlow = Flow::withoutTenancy()
                ->whereKey($flow->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) ($lockedFlow->draft_revision ?? 0) !== $expectedDraftRevision) {
                throw new StaleDraftException(
                    $lockedFlow->draft_graph ?? [],
                    (int) ($lockedFlow->draft_revision ?? 0),
                );
            }

            $version = FlowVersion::create([
                'flow_id' => $lockedFlow->id,
                // From the flow, never from the ambient tenant. The flow was
                // reached through a scoped read, so it is the authority on which
                // tenant this version belongs to — and a publish can legitimately
                // happen in a console or queue context with no ambient tenant,
                // where stamping ambient would write null and make the version
                // invisible to every scoped read afterwards.
                //
                // Enforcement lives in FlowVersion's creating() hook, which
                // reads the flow itself and throws on any contradiction, so
                // this line is belt-and-braces: it states at the publish site
                // which tenant a version belongs to, rather than leaving the
                // reader to infer it from a hook two files away. Removing it
                // would not open the mismatch hole (the hook closes that); it
                // would only make the intent implicit.
                'tenant_id' => $lockedFlow->tenant_id,
                'version' => ((int) $lockedFlow->versions()->max('version')) + 1,
                'graph' => $graph,
                'content_hash' => hash('sha256', $encodedGraph),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ]);

            $activation = $this->compileActivation->compile(
                $lockedFlow,
                $version,
                $compiledGraph,
                $definitions,
            );
            $webhook = $activation->driver === WebhookTriggerDriver::key()
                ? $this->webhookCredentials->forPublication($lockedFlow)
                : null;

            // The draft became this version, so it is no longer pending work. Left
            // behind, the editor reopens showing an already-published graph as
            // unsaved changes.
            //
            // draft_revision is deliberately NOT reset. It is a monotonic
            // concurrency token, and rewinding it to 0 broke that in two ways at
            // once. An author who saved (revision 1) and then published would see
            // their next autosave — still carrying token 1 — refused as stale
            // against a server holding 0, offering an empty graph as the winner to
            // the only person editing. And worse, a rewound counter re-mints
            // numbers: a pre-publish token 1 matches the post-publish revision 1
            // of somebody else's brand new draft, so a stale write sails through
            // and destroys it. Monotonicity is the whole property the counter
            // exists to have.
            //
            // Nothing needs the reset. A freshly loaded client already knows the
            // current revision because edit() ships draft_revision in its props,
            // and publish() echoes it back for a client that stays open.
            $lockedFlow->update([
                'current_version_id' => $version->id,
                'status' => 'active',
                'draft_graph' => null,
                'draft_updated_at' => null,
            ]);

            return [new PublishResult(
                $version,
                $webhook['url'] ?? null,
                $webhook['secret'] ?? null,
            ), $lockedFlow];
        });

        // Transaction work happens on the locked reload so a rollback can never
        // leave the caller claiming attributes that the database discarded.
        // Only after commit do we adopt the authoritative raw row and invalidate
        // relation snapshots that publication made obsolete.
        $flow->setRawAttributes($publishedFlow->getAttributes(), true);
        foreach (['currentVersion', 'versions', 'triggerActivation', 'activation'] as $relation) {
            $flow->unsetRelation($relation);
        }

        return $publishResult;
    }
}
