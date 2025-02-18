<?php

namespace FammSupport\Http\Controllers\Api;

use FammSupport\Models\Select;

class ObjectController
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
                    'value' => $item->value,
                    'label' => $item->label,
                    'icon' => $item->icon,
                    'disabled' => $item->disabled,
                ];
            });
        } else {
            $output = [];
            foreach ($item->options->groupBy('groupName') as $name => $options) {
                $output[] = [
                    'label' => $name,
                    'options' => $options->map(function ($item) {
                        return [
                            'value' => $item->value,
                            'label' => $item->label,
                            'icon' => $item->icon,
                            'disabled' => $item->disabled,
                        ];
                    }),
                ];
            }
        }

        return [
            "displayName" => $item['displayName'],
            "description" => $item['description'],
            "options" => $output
        ];
    }
}