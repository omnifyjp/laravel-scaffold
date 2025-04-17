<?php

namespace OmnifyJP\LaravelScaffold\Models\Relations;

trait HasMorphToOne
{
    public function morphToOne($related, $name, $table = null, $foreignPivotKey = null, $relatedPivotKey = null, $parentKey = null, $relatedKey = null, $inverse = false): MorphToOne
    {
        $instance = $this->newRelatedInstance($related);

        return new MorphToOne(
            $instance->newQuery(),
            $this,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $inverse);
    }
}
