<?php

if (! function_exists('support_omnify_path')) {
    function support_omnify_path($path = null): string
    {
        return base_path('.omnify/' . $path);
    }
}
