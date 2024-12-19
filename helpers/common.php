<?php

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
