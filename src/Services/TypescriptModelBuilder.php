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
use FammCore\Services\PropertyTypes\PasswordType;
use FammCore\Services\PropertyTypes\StringType;
use FammCore\Services\PropertyTypes\TextType;
use FammCore\Services\PropertyTypes\TimestampType;
use FammSupport\Models\Select;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TypescriptModelBuilder
{
    public function build(): void
    {
        make_dir(famm_path('ts/Models/Base'));
//        make_dir(famm_path('ts/Enums'));
        make_dir(famm_path('ts/Selects'));
        foreach (Select::all() as $item) {
            
        }
        
        
        foreach (ObjectModel::all() as $object) {
            $output = "export default interface " . $object->objectName . "Base {\n";
            $model_imports = [];
            /** @var Property $property */
            foreach ($object->properties as $property) {

                $type = $property->type();
                $output .= "\t// " . $property->displayName . "\n";
                if ($property->type == PropertyType::TYPE_JAPAN_ADDRESS) {
                    $output .= "\t" . $property->propertyName . "_postal_code: string;\n";
                    $output .= "\t" . $property->propertyName . "_prefecture: string;\n";
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
            File::put(famm_path('ts/Models/Base/' . $object->objectName . "Base.ts"), "/**
 * This file is automatically generated. Do not edit.
 * このファイルは自動生成されています。編集しないでください。
 * File này được tạo tự động. Vui lòng không chỉnh sửa.
 * 이 파일은 자동으로 생성되었습니다. 편집하지 마십시오.
 * 此文件是自动生成的。请勿编辑。
 */\n\n" . $imports . "\n" . $output);


//            if (!File::exists(famm_path('ts/Models/' . $object->objectName . ".ts"))) {
            $output = "import " . $object->objectName . "Base from './Base/" . $object->objectName . "Base';\n\n";
            $output .= "export default interface " . $object->objectName . " extends " . $object->objectName . "Base {}";
            File::put(famm_path('ts/Models/' . $object->objectName . ".ts"), $output);
//            }
            /**
             *
             */
        }
    }

}
