<?php

namespace FammSupport\Http\Controllers\Api;

use FammSupport\Models\Select;

class ObjectController
{
    const CACHE_KEY_SYSTEM_API_COLLECTIONS = 'cache@api.objects';

    public function list()
    {
        return cache()->remember(self::CACHE_KEY_SYSTEM_API_COLLECTIONS, now()->addMinutes(30), function () {
            return famm_schema()->all();
        });
    }

    public function getObject()
    {
        
    }

    public function getProperty()
    {
        
    }
}