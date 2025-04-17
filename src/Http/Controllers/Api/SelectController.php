<?php

namespace OmnifyJP\LaravelScaffold\Http\Controllers\Api;

use OmnifyJP\LaravelScaffold\Models\Select;

class SelectController
{
    public function list(): array
    {
        $selects = [];
        foreach (Select::with('options')->get() as $item) {
            $selects[$item->selectName] = $this->transform($item);
        }

        return $selects;
    }

    public function show($selectName): array
    {
        $select = Select::with('options')->where('selectName', $selectName)->firstOrFail();

        return $this->transform($select);
    }

    private function transform(Select $item): array
    {
        $options = $item->options->groupBy('groupName');
        if (count($options) == 1) {
            $output = $options->first()->map(function ($item) {
                return [
                    'id' => $item->id,
                    'value' => $item->value,
                    'label' => $item->label,
                    'icon' => $item->icon,
                    'disabled' => $item->disabled,
                    'properties' => $item->properties,
                ];
            });
        } else {
            $output = [];
            foreach ($item->options->groupBy('groupName') as $name => $options) {
                $output[] = [
                    'label' => $name,
                    'options' => $options->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'value' => $item->value,
                            'label' => $item->label,
                            'icon' => $item->icon,
                            'disabled' => $item->disabled,
                            'properties' => $item->properties,
                        ];
                    }),
                ];
            }
        }

        return [
            'displayName' => $item['displayName'],
            'description' => $item['description'],
            'options' => $output,
        ];
    }
}
