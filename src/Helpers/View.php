<?php

namespace FammSupport\Helpers;

use FammSupport\DynamicSchemaProcessor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class View
{
    private Collection $schemas;

    public function __construct()
    {
        $schemas = collect();
        if (File::exists(omnify_path('view-lock.json'))) {
            $schemas = collect(json_decode(File::get(omnify_path('view-lock.json')), 1) ?? []);
        }
        $this->schemas = $schemas;
    }

    public function all(): Collection
    {
        return $this->schemas;
    }

    public function get($entityName, $viewType = 'list'): ?Collection
    {
        $processor = new DynamicSchemaProcessor;

        return collect($processor->processSchema($this->schemas[$entityName . '::' . $viewType])) ?? null;
    }
}
