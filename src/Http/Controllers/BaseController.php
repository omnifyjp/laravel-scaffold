<?php

namespace OmnifyJP\LaravelScaffold\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controller;
use OmnifyJP\LaravelScaffold\Helpers\Schema;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class BaseController extends Controller
{
    /**
     * @throws Exception
     */
    protected function getModelClass($objectName)
    {
        $modelClass = '\\FammApp\\Models\\'.$objectName;

        if (class_exists($modelClass)) {
            return $modelClass;
        }
        throw new Exception("Model class '".$modelClass."' not found");
    }

    protected function getModelBuilder($objectName): Builder
    {
        try {
            $modelClass = $this->getModelClass($objectName);
        } catch (Exception $e) {
            abort(404, $e->getMessage());
        }

        /** @var Builder $builder */
        return $modelClass::builder();

    }

    protected function getSchema($objectName)
    {
        try {
            return app(Schema::class)->get($objectName);
        } catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
            abort(404, $e->getMessage());
        }
    }

    protected function getSchemaAttributeTarget($objectName, $propertyName)
    {
        return app(Schema::class)->getTarget($objectName, $propertyName);
    }
}
