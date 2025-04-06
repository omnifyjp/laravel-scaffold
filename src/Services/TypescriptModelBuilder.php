<?php

namespace FammSupport\Services;

use FammCore\Models\System\ObjectModel;
use FammCore\Models\System\Property;
use FammCore\Models\System\PropertyType;
use FammCore\Services\PropertyTypes\AssociationType;
use FammCore\Services\PropertyTypes\BigIntType;
use FammCore\Services\PropertyTypes\DateType;
use FammCore\Services\PropertyTypes\EnumType;
use FammCore\Services\PropertyTypes\FloatType;
use FammCore\Services\PropertyTypes\IdType;
use FammCore\Services\PropertyTypes\IntType;
use FammCore\Services\PropertyTypes\MultiSelectType;
use FammCore\Services\PropertyTypes\PasswordType;
use FammCore\Services\PropertyTypes\SelectType;
use FammCore\Services\PropertyTypes\StringType;
use FammCore\Services\PropertyTypes\TextType;
use FammCore\Services\PropertyTypes\TimestampType;
use FammSupport\Models\Select;
use FammSupport\Models\SelectOption;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TypescriptModelBuilder
{
    public function build(): void
    {
        make_dir(famm_path('ts/Models/Base'));
//        make_dir(famm_path('ts/Enums'));
        make_dir(famm_path('ts/Selects'));

        /** @var Select $select */
        foreach (Select::all() as $select) {
            $output = "";
            $name = Str::ucfirst(Str::camel(str_replace("::", "_", $select->selectName)));

            //
            $output .= "export type " . $name . "Value = " . implode("|", array_map(function ($item) {
                    return '"' . addslashes($item) . '"';
                }, $select->options->pluck('value')->toArray())) . ";\n";
            $output .= "export type " . $name . "Label = " . implode("|", array_map(function ($item) {
                    return '"' . addslashes($item) . '"';
                }, $select->options->pluck('label')->toArray())) . ";\n";

            $output .= "export type " . $name . "Type = {\n\tid: number;\n\tvalue: " . $name . "Value;\n\tlabel: " . $name . "Label;\n}\n\n";
            $output .= "export type  " . $name . "DataType = {\n\t[key: string]: " . $name . "Type;\n}\n\n";


            $output .= "export const " . $name . "Data = {\n";
            /** @var SelectOption $option */
            foreach ($select->options as $option) {
                $output .= "\t" . Str::upper($option->value) . ": {\n\t\tid: " . $option->id . ",\n";
                $output .= "\t\tvalue: '" . $option->value . "',\n";
                $output .= "\t\tlabel: '" . $option->label . "',\n";
                $output .= "\t},\n";
            }
            $output .= "} as const\n";

            $template = file_get_contents(support_path('stubs/ts/select-model.stub'));
            $template = str_replace("// ##BODY## //", $output, $template);
            $template = str_replace("ModelName", $name, $template);

            File::put(famm_path('ts/Selects/' . $name . '.ts'), $template);

        }


        foreach (ObjectModel::all() as $object) {
            $output = "export default interface " . $object->objectName . "Base {\n";
            $model_imports = [];
            $select_imports = [];
            /** @var Property $property */
            foreach ($object->properties as $property) {

                $type = $property->type();
                $output .= "\t// " . $property->displayName . "\n";
                if ($property->type == PropertyType::TYPE_JAPAN_ADDRESS) {
                    $select_imports[] = "GlobalPrefecture";
                    $output .= "\t" . $property->propertyName . "_postal_code: string;\n";
                    $output .= "\t" . $property->propertyName . "_prefecture: GlobalPrefectureType;\n";
                    $output .= "\t" . $property->propertyName . "_address1: string;\n";
                    $output .= "\t" . $property->propertyName . "_address2: string;\n";
                    $output .= "\t" . $property->propertyName . "_address3: string;\n";

                } elseif ($property->type == PropertyType::TYPE_JAPAN_PERSON_NAME) {
                    $output .= "\t" . $property->propertyName . "_lastname: string;\n";
                    $output .= "\t" . $property->propertyName . "_firstname: string;\n";
                    $output .= "\t" . $property->propertyName . "_kana_lastname: string;\n";
                    $output .= "\t" . $property->propertyName . "_kana_firstname: string;\n";

                } elseif ($type instanceof AssociationType) {
                    $model_imports[] = $property->target;
                    if ($property->relation == AssociationType::ONE_TO_ONE || $property->relation == AssociationType::MANY_TO_ONE) {
                        $output .= "\t" . $property->propertyName . ": " . $property->target . ";\n";
                    } else {
                        $output .= "\t" . $property->propertyName . ": Array<" . $property->target . ">;\n";
                    }
                } elseif ($type instanceof SelectType) {
                    $selectName = Str::ucfirst(Str::camel(str_replace("::", "_", $property->select)));
                    $output .= "\t" . $property->propertyName . ": " . $selectName . "Type;\n";
                    $select_imports[] = $selectName;
                } elseif ($type instanceof MultiSelectType) {
                    $selectName = Str::ucfirst(Str::camel(str_replace("::", "_", $property->select)));
                    $output .= "\t" . $property->propertyName . ": Array<" . $selectName . "Type>;\n";
                    $select_imports[] = $selectName;

                } elseif ($type instanceof IdType || $type instanceof IntType || $type instanceof BigIntType || $type instanceof FloatType) {
                    $output .= "\t" . $property->propertyName . ": number;\n";
                } elseif ($type instanceof TextType || $type instanceof StringType || $type instanceof PasswordType || $type instanceof DateType || $type instanceof TimestampType) {
                    $output .= "\t" . $property->propertyName . ": string;\n";

                } elseif ($type instanceof EnumType) {
                    $enumName = $property->objectName . Str::ucfirst(Str::camel($property->propertyName)) . "Enum";
                    $output .= "\t" . $property->propertyName . ": $enumName;\n";

                } else {
                    $output .= "\t" . $property->propertyName . ": any;\n";
                }
            }

            $output .= "}\n";


            foreach ($object->properties as $property) {
                $type = $property->type();
                if ($type instanceof EnumType) {
                    $enumName = $property->objectName . Str::ucfirst(Str::camel($property->propertyName)) . "Enum";
                    $output .= "export enum " . $enumName . "  {\n";

                    foreach ($property->enum as $item) {
                        $output .= "\t" . Str::upper($item['value']) . "= '" . $item['value'] . "',\n";
                    }
                    $output .= "}\n";
                    $labelName = $property->objectName . Str::ucfirst(Str::camel($property->propertyName)) . "Labels";
                    $output .= "export const " . $labelName . ":Record<" . $enumName . ", string> = {\n";

                    foreach ($property->enum as $item) {
                        $output .= "\t[" . $enumName . "." . Str::upper($item['value']) . "]: '" . $item['label'] . "',\n";
                    }
                    $output .= "}\n";
                }
            }

            $imports = "";
            foreach (array_unique($model_imports) as $item) {
                $imports .= "import " . $item . " from '../" . $item . "';\n";
            }
            foreach (array_unique($select_imports) as $item) {
                $imports .= "import {" . $item . "Type} from '../../Selects/" . $item . "';\n";
            }

            //                    import { GlobalPharmacyStatusType } from '@famm/Selects/GlobalPharmacyStatus';

            File::put(famm_path('ts/Models/Base/' . $object->objectName . "Base.ts"), "/**
 * This file is automatically generated. Do not edit.
 * このファイルは自動生成されています。編集しないでください。
 * File này được tạo tự động. Vui lòng không chỉnh sửa.
 * 이 파일은 자동으로 생성되었습니다. 편집하지 마십시오.
 * 此文件是自动生成的。请勿编辑。
 */\n\n" . $imports . "\n" . $output);


            if (!File::exists(famm_path('ts/Models/' . $object->objectName . ".ts"))) {
                $output = "import " . $object->objectName . "Base from './Base/" . $object->objectName . "Base';\n\n";
                $output .= "export default interface " . $object->objectName . " extends " . $object->objectName . "Base {}";
                File::put(famm_path('ts/Models/' . $object->objectName . ".ts"), $output);
            }
            /**
             *
             */
        }
    }

}
