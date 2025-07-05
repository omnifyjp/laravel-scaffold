<?php

namespace OmnifyJP\LaravelScaffold\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use OmnifyJP\LaravelScaffold\Models\Traits\UseQuery;
use OmnifyJP\LaravelScaffold\Services\FormulaParser;
use OmnifyJP\LaravelScaffold\Services\SpreadsheetHelper;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

/**
 * @property string $generation_type
 * @property string $base_collection
 */
class GeneratedDocument extends Model
{
    use SoftDeletes;
    use UseQuery;

    protected $primaryKey = 'id';

    protected $table = '_generated_documents';

    protected $fillable = [
        'type',
        'key',
        'name',
        'description',
        'parameters',
        'document_id',
        'generation_type',
        'base_collection',
        'morph_type',
        'morph_id',
        'deleted_at',
    ];

    protected $hidden = [
        'base_collection',
        'datasource_id',
        'key',
        'morph_type',
        'morph_id',

    ];

    protected $casts = [
        'parameters' => 'json',
    ];

    public function datasource(): HasMany
    {
        return $this->hasMany(GeneratedDocumentCombinationParameter::class, '_generated_document_id');
    }

    public function combination_parameters(): HasMany
    {
        return $this->hasMany(GeneratedDocumentCombinationParameter::class, '_generated_document_id');
    }

    public function base(): MorphTo
    {
        return $this->morphTo('morph');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @throws Exception
     */
    public function getFieldValues(): array
    {
        $datasource = $this->datasource->pluck('mapping', 'name');
        $fields = [];

        foreach ($this->document->fields as $field) {
            $path = $field->combination_variable;
            if (preg_match('/^\$(\w+)(.\w+)+$/', $field->combination_variable, $matches)) {
                $path = substr($field->combination_variable, 1);
            }

            $value = $this->getValueFromPath($path, $datasource);
            if ($field->combination_formula) {
                $parser = new FormulaParser($value, $datasource);
                $value = $parser->parse($field->combination_formula);
            }
            $fields[$field->kind][$field->name] = [
                'value' => $value,
                'action_type' => $field->action_type,
                'coordinate' => $field->coordinate,
                'meta' => $field,
            ];

        }

        return $fields;

    }

    /**
     * @throws Exception
     */
    public function generateDocument(): void
    {
        $spreadsheet = IOFactory::load($this->document->file->getPath());
        $pattern = '/\{\{(.*?)\}\}/';

        $fields = $this->getFieldValues();
        $values = $fields[DocumentField::KIND_TEXT];
        $images = $fields[DocumentField::KIND_IMAGE];

        /**
         * DRAWING PROCESS
         */
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $drawingCollection = $sheet->getDrawingCollection();
            $newDrawingCollection = [];
            foreach ($drawingCollection as $drawing) {
                if (SpreadsheetHelper::isDrawingAnImage($drawing)) {
                    if (preg_match_all($pattern, $drawing->getName(), $matches)) {
                        foreach ($matches[1] as $variableName) {
                            $value = $values[$variableName]['value'] ?? null;
                            /** @var Drawing $newDrawing */
                            $newDrawing = clone $drawing;
                            $newDrawing->setName($matches[1][0].'_REPLACED');
                            $newDrawing->setDescription($matches[1][0].'_REPLACED');

                            if ($images[$variableName]['action_type'] == DocumentField::ACTION_TYPE_ACTION_TYPE_REPLACE) {
                                $newDrawing->setPath(public_path('avatar.png'));
                                $newDrawing->setResizeProportional(false);
                                $newDrawingCollection[] = $newDrawing;
                            }

                            if ($images[$variableName]['action_type'] == DocumentField::ACTION_TYPE_ACTION_TYPE_VISIBILITY) {
                                if ($value || $variableName == 'MALE' || $variableName == 'SINGLE') {
                                    $newDrawingCollection[] = $newDrawing;
                                }
                            }
                        }
                    }
                }
            }

            // REMOVE ALL DRAWING
            foreach (array_keys($sheet->getDrawingCollection()->getArrayCopy()) as $key) {
                unset($drawingCollection[$key]);
            }
            // ADD NEW DRAWING
            foreach ($newDrawingCollection as $newDrawing) {
                $newDrawing->setWorksheet($sheet);
            }

            /**
             * DRAWING PROCESS
             */
            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);

                /** @var Cell $cell */
                foreach ($cellIterator as $cell) {
                    $cellValue = $cell->getValue();

                    if (preg_match_all($pattern, $cellValue, $matches)) {
                        foreach ($matches[1] as $variableName) {
                            $newValue = str_replace(
                                '{{'.$variableName.'}}',
                                $values[$variableName]['value'] ?? null,
                                $cellValue
                            );
                            $cell->setValue($newValue);
                        }
                    }
                }
            }
            //
        }

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setIncludeCharts(true);
        $dir = 'documents/'.($this->created_at ?? now())->format('Ymd').'/DOC_'.$this->document->id.'/NO_'.$this->id;
        Storage::disk('local')->makeDirectory($dir);
        $path = $dir.'/'.md5($this->id).'.xlsx';
        $writer->save(Storage::disk('local')->path($path));
        $this->file()->delete();
        $file = $this->file()->create([
            'path' => $path,
            'disk' => 'local',
            'name' => $this->name.'.xlsx',
            'mime' => Storage::disk('local')->mimeType($path),
        ]);
        $file->updated_at = now();
        $file->save();
    }

    public function file(): MorphOne
    {
        return $this->morphOne(FileUpload::class, 'morph');
    }

    private function getValueFromPath($path, $data)
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_null($current)) {
                return null;
            }
            $current = $current[$key] ?? null;
        }

        return $current;
    }
}
