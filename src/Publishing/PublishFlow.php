<?php

namespace Nodeflow\Publishing;

use Illuminate\Support\Facades\DB;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;

class PublishFlow
{
    public function __construct(private GraphValidator $validator) {}

    public function publish(Flow $flow, array $graph, ?string $publishedBy = null): FlowVersion
    {
        $result = $this->validator->validate(Graph::fromArray($graph));

        if (! $result->passes()) {
            throw new GraphInvalidException($result->errors(), $result->nodeErrors());
        }

        return DB::transaction(function () use ($flow, $graph, $publishedBy) {
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
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
                'tenant_id' => $flow->tenant_id,
                'version' => ((int) $flow->versions()->max('version')) + 1,
                'graph' => $graph,
                'content_hash' => hash('sha256', json_encode($graph)),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ]);

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
            $flow->update([
                'current_version_id' => $version->id,
                'status' => 'active',
                'draft_graph' => null,
                'draft_updated_at' => null,
            ]);

            return $version;
        });
    }
}
