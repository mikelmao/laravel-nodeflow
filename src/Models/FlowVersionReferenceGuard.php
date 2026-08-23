<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;

final class FlowVersionReferenceGuard
{
    public static function assert(Model $model, string $attribute, bool $nullable): void
    {
        $referenceId = $model->getAttribute($attribute);

        if ($referenceId === null) {
            if ($nullable) {
                return;
            }

            throw InvalidFlowVersionReferenceException::forMissing(
                $model::class,
                $attribute,
                null,
            );
        }

        $version = FlowVersion::withoutTenancy()->find($referenceId);

        if ($version === null) {
            throw InvalidFlowVersionReferenceException::forMissing(
                $model::class,
                $attribute,
                $referenceId,
            );
        }

        if ((string) $version->tenant_id !== (string) $model->getAttribute('tenant_id')) {
            throw CrossTenantWriteException::forReferenceMismatch(
                $model::class,
                $attribute,
                $version->id,
                $model->getAttribute('tenant_id'),
                $version->tenant_id,
            );
        }
    }
}
