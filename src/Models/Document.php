<?php

namespace FammSupport\Models;

use Exception;
use FammSupport\Models\Traits\UseQuery;
use FammSupport\Services\SpreadsheetHelper;
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

    public function mapping_targets(): HasMany
    {
        return $this->hasMany(DocumentMappingTarget::class, 'document_id');
    }

    public function datasource(): HasMany
    {
        return $this->hasMany(DocumentMappingTarget::class, 'document_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentField::class, 'document_id');
    }

    public function file(): MorphOne
    {
        return $this->morphOne(FileUpload::class, 'morph');
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
    public function getMappingTargets(Model $baseModel): void
    {
        $schema = static::schema();

        $targets = [];
        if ($this->generation_type == 'MULTIPLE') {
            $list_keys = [];

            foreach ($this->mapping_targets as $mapping_target) {
                if ($mapping_target->type == DocumentMappingTarget::TYPE_ATTRIBUTE) {
                    if (!isset($schema['attributes'][$mapping_target->attributeName])) {
                        throw new Exception('Attribute not found');
                    }
                    if ($baseModel->{$mapping_target->attributeName}() instanceof BelongsTo) {
                        $targets[$mapping_target->name] = collect([$baseModel->{$mapping_target->attributeName}]);
                    } else {
                        $targets[$mapping_target->name] = $baseModel->{$mapping_target->attributeName};
                    }
                }
            }
            foreach ($this->getDocumentCriteria($targets) as $items) {
                $keys = [];
                $subtitle = [];
                $description = '';
                foreach ($items as $key => $val) {
                    $keys[$key] = $val->id;
                    $subtitle[$key] = $val->_title;
                    $description .= $key . ':' . $val->_title . "\n";
                }
                ksort($keys);

                $key = sha1(serialize([$keys, get_class($baseModel), $baseModel->id]));
                $list_keys[] = $key;

                $generated_document = GeneratedDocument::withTrashed(true)->firstOrCreate([
                    'document_id' => $this->id,
                    'morph_type' => get_class($baseModel),
                    'morph_id' => $baseModel->id,
                    'key' => $key,
                ], [
                    'name' => $this->name . '【' . implode('-', $subtitle) . '】',
                    'description' => $description,
                ]);
                $generated_document->update([
                    'name' => $this->name . '【' . implode('-', $subtitle) . '】',
                    'description' => $description,
                ]);
                if ($generated_document->trashed()) {
                    $generated_document->restore();
                }
                foreach ($items as $name => $item) {
                    /** @var GeneratedDocument $generated_document */
                    $generated_document->mapping_targets()->updateOrCreate([
                        'name' => $name,
                    ], [
                        'mapping_type' => get_class($item),
                        'mapping_id' => $item->id,
                    ]);
                }
            }

            GeneratedDocument::query()
//                ->where('document_id', $this->id)
                ->whereNotIn('key', $list_keys)->delete();
        }

    }

    public function getDocumentCriteria($criteria): array
    {
        $result = [[]];
        foreach ($criteria as $key => $values) {
            $temp = [];
            foreach ($result as $item) {
                foreach ($values as $value) {
                    $temp[] = $item + [$key => $value];
                }
            }
            $result = $temp;
        }

        return $result;
    }
}
