<?php

namespace OmnifyJP\LaravelScaffold\Models;

use Exception;
use OmnifyJP\LaravelScaffold\Models\Traits\UseQuery;
use OmnifyJP\LaravelScaffold\Services\SpreadsheetHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * @property string $generation_type
 * @property string $base_collection
 */
class Document extends Model
{
    use UseQuery;

    const GENERATION_TYPE_SINGLE = 'SINGLE';

    protected $primaryKey = 'id';

    protected $table = '_documents';

    protected $fillable = [
        'name',
        'type',
        'generation_type',
        'base_collection',
        'description',
        'form_number',
    ];

    protected $hidden = [
        'base_collection',
        'created_at',
        'updated_at',
        'datasource_id',
    ];

    protected $casts = [
    ];

    public function combination_parameters(): HasMany
    {
        return $this->hasMany(DocumentCombinationParameter::class, 'document_id');
    }

    public function datasource(): HasMany
    {
        return $this->hasMany(DocumentCombinationParameter::class, 'document_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class, 'document_id');
    }

    public function file(): MorphOne
    {
        return $this->morphOne(FileUpload::class, 'morph');
    }

    public function generated_documents()
    {
        return $this->hasMany(GeneratedDocument::class, 'document_id');
    }

    public function createFields(): Collection
    {
        ini_set('memory_limit', '-1');
        $spreadsheet = IOFactory::load($this->file->getPath());
        $pattern = '/\{\{(.*?)\}\}/';

        $fields = collect();

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $drawingCollection = $sheet->getDrawingCollection();

            foreach ($drawingCollection as $drawing) {
                if (SpreadsheetHelper::isDrawingAnImage($drawing)) {
                    if (preg_match_all($pattern, $drawing->getName(), $matches)) {
                        $field = $this->fields()->updateOrCreate([
                            'kind' => DocumentField::KIND_IMAGE,
                            'name' => $matches[1][0],
                            'coordinate' => $drawing->getCoordinates(),
                        ]);
                        $fields->push($field);
                    }
                }
            }

            foreach ($sheet->getRowIterator() as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);
                /** @var Cell $cell */
                foreach ($cellIterator as $cell) {
                    $cellValue = $cell->getValue();
                    if (preg_match_all($pattern, $cellValue, $matches)) {
                        $field = $this->fields()->updateOrCreate([
                            'name' => $matches[1][0],
                            'coordinate' => $cell->getCoordinate(),
                        ]);
                        $fields->push($field);
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface|Exception
     */
    public function generateDocuments(Model $baseModel): void
    {
        $schema = $baseModel->schema();
        $targets = [];
        $list_keys = [];

        if ($this->generation_type == 'COMBINATION') {
            foreach ($this->combination_parameters as $combination_target) {
                if ($combination_target->type == DocumentCombinationParameter::TYPE_ATTRIBUTE) {
                    if (! isset($schema['attributes'][$combination_target->propertyName])) {
                        throw new Exception('Attribute not found');
                    }
                    if ($baseModel->{$combination_target->propertyName}() instanceof BelongsTo) {
                        $targets[$combination_target->name] = collect([$baseModel->{$combination_target->propertyName}]);
                    } else {
                        $targets[$combination_target->name] = $baseModel->{$combination_target->propertyName};
                    }
                }
            }

            //            dd($this->getDocumentCriteria($targets));
            foreach ($this->getDocumentCriteria($targets) as $items) {
                $keys = [];
                $subtitle = [];
                $description = '';
                $parameters = [];
                foreach ($items as $key => $val) {
                    $keys[$key] = $val->id;
                    $subtitle[$key] = $val->_title;
                    $description .= $key.':'.$val->_title."\n";
                    $parameters[$key] = $val->_title;
                }
                ksort($keys);

                $key = sha1(serialize([$keys, get_class($baseModel), $baseModel->id]));
                $list_keys[] = $key;

                $generated_document = GeneratedDocument::withTrashed(true)->firstOrCreate([
                    'document_id' => $this->id,
                    'morph_type' => class_basename($baseModel),
                    'morph_id' => $baseModel->id,
                    'key' => $key,
                ], [
                    'name' => $this->name.'【'.implode('-', $subtitle).'】',
                    'description' => $description,
                    'parameters' => $parameters,
                ]);
                $generated_document->update([
                    'name' => $this->name.'【'.implode('-', $subtitle).'】',
                    'description' => $description,
                ]);
                if ($generated_document->trashed()) {
                    $generated_document->restore();
                }
                foreach ($items as $name => $item) {
                    /** @var GeneratedDocument $generated_document */
                    $generated_document->combination_parameters()->updateOrCreate([
                        'name' => $name,
                    ], [
                        'combination_type' => class_basename($item),
                        'combination_id' => $item->id,
                    ]);
                }
            }

        } else {
            $key = sha1(serialize([get_class($baseModel), $baseModel->id]));
            $list_keys[] = $key;

            $generated_document = GeneratedDocument::withTrashed(true)->firstOrCreate([
                'document_id' => $this->id,
                'morph_type' => class_basename($baseModel),
                'morph_id' => $baseModel->id,
                'key' => $key,
            ], [
                'name' => $this->name,
            ]);
            if ($generated_document->trashed()) {
                $generated_document->restore();
            }
        }

        GeneratedDocument::query()
            ->where('document_id', $this->id)
            ->whereNotIn('key', $list_keys)->delete();
    }

    public function getDocumentCriteria($criteria): array
    {
        $result = [[]];
        foreach ($criteria as $key => $values) {
            $temp = [];

            foreach ($result as $item) {
                foreach ($values as $value) {
                    if ($value != null) {
                        $temp[] = $item + [$key => $value];
                    }
                }
            }
            $result = $temp;
        }

        return $result;
    }
}
