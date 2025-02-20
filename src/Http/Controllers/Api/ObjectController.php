<?php

namespace FammSupport\Http\Controllers\Api;

use FammSupport\Models\Select;

class ObjectController
{
    const CACHE_KEY_SYSTEM_API_COLLECTIONS = 'cache@api.objects';
    private $objects;

    public function __construct()
    {
        $this->objects = famm_schema()->all();

    }

    public function list()
    {
        return $this->objects;
    }

    public function getObject($objectName)
    {
        $object = $this->objects[$objectName] ?? null;
        if (!$object) abort(404);
        return $object;
    }

    public function getProperty($objectName, $propertyName)
    {
        $object = $this->objects[$objectName] ?? null;
        if (!$object) abort(404);
        $property = $object['properties'][$propertyName] ?? null;
        if (!$property) abort(404);
        return $property;
    }
}