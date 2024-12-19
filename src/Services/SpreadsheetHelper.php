<?php

namespace FammSupport\Services;

use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class SpreadsheetHelper
{
    static function isDrawingAnImage($drawing): bool
    {
        if ($drawing instanceof Drawing) {
            $extension = strtolower(pathinfo($drawing->getPath(), PATHINFO_EXTENSION));
            return in_array($extension, ['jpg', 'jpeg', 'gif', 'png']);
        }
        return false;
    }

}