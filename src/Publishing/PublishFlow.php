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
            throw new GraphInvalidException($result->errors());
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
                'tenant_id' => $flow->tenant_id,
                'version' => ((int) $flow->versions()->max('version')) + 1,
                'graph' => $graph,
                'content_hash' => hash('sha256', json_encode($graph)),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ]);

            $flow->update(['current_version_id' => $version->id, 'status' => 'active']);

            return $version;
        });
    }
}
