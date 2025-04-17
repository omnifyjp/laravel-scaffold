<?php

namespace FammSupport\Models\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

class MorphToOne extends MorphToMany
{
    public function match(array $models, $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation(
                $relation,
                $results->first()
            );
        }

        return $models;
    }
}
