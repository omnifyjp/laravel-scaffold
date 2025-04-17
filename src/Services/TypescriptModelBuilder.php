<?php

namespace FammSupport\Services;

use FammSupport\Models\Select;
use FammSupport\Models\SelectOption;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TypescriptModelBuilder
{
    public function build(): void
    {
        /** @var Select $select */
        foreach (Select::all() as $select) {
            $output = '';
            $name = Str::ucfirst(Str::camel(str_replace('::', '_', $select->selectName)));

            //
            $output .= 'export type '.$name.'Value = '.implode('|', array_map(function ($item) {
                return '"'.addslashes($item).'"';
            }, $select->options->pluck('value')->toArray())).";\n";
            $output .= 'export type '.$name.'Label = '.implode('|', array_map(function ($item) {
                return '"'.addslashes($item).'"';
            }, $select->options->pluck('label')->toArray())).";\n";

            $output .= 'export type '.$name."Type = {\n\tid: number;\n\tvalue: ".$name."Value;\n\tlabel: ".$name."Label;\n}\n\n";
            $output .= 'export type  '.$name."DataType = {\n\t[key: string]: ".$name."Type;\n}\n\n";

            $output .= 'export const '.$name."Data = {\n";
            /** @var SelectOption $option */
            foreach ($select->options as $option) {
                $output .= "\t".Str::upper($option->value).": {\n\t\tid: ".$option->id.",\n";
                $output .= "\t\tvalue: '".$option->value."',\n";
                $output .= "\t\tlabel: '".$option->label."',\n";
                $output .= "\t},\n";
            }
            $output .= "} as const\n";

            $template = file_get_contents(support_path('stubs/ts/select-model.stub'));
            $template = str_replace('// ##BODY## //', $output, $template);
            $template = str_replace('ModelName', $name, $template);

            File::put(omnify_path('ts/Selects/'.$name.'.ts'), $template);

        }
    }
}
