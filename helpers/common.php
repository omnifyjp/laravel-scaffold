<?php

use FammSupport\Helpers\Schema;

function support_path($path = null): string
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . '../' . trim($path, '/');
    $realpath = realpath($path);

    return $realpath ? $realpath : $path;
}


if (!function_exists('famm_path')) {
    function famm_path($path = null): string
    {
        return base_path('.famm/' . $path);
    }
}

if (!function_exists('famm_schema')) {
//    function famm_schema($entityName = null)
//    {
//        if ($entityName) return famm_schema()->get($entityName);
//
//        if (class_exists('\FammSupport\Helpers\Schema')) {
//            return app(Schema::class);
//        }
//        return null;
//    }
}
