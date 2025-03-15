<?php

namespace FammSupport\Http\Controllers\Api;

use FammSupport\Http\Controllers\BaseController;
use FammSupport\Http\Resources\CollectionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CollectionController extends BaseController
{
    public function index(Request $request, $objectName): AnonymousResourceCollection
    {
        $builder = $this->getModelBuilder($objectName);
        Gate::authorize('list', $builder->newModelInstance());
        return CollectionResource::collection($builder->latest()->paginate($request->get('perPage', 10)));
    }

}