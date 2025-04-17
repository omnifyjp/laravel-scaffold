<?php

namespace OmnifyJP\LaravelScaffold\Http\Controllers\Api;

use OmnifyJP\LaravelScaffold\Http\Controllers\BaseController;
use OmnifyJP\LaravelScaffold\Http\Resources\CollectionResource;
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
