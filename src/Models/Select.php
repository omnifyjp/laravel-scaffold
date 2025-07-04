<?php

namespace OmnifyJP\LaravelScaffold\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OmnifyJP\LaravelScaffold\Models\Traits\UseQuery;

/**
 * @property mixed $options
 * @property mixed $selectName
 */
class Select extends Model
{
    use UseQuery;

    protected $table = '_selects';

    protected $primaryKey = 'selectName';

    protected $keyType = 'string';

    protected $fillable = [
        'selectName',
        'displayName',
        'description',
        'autoCreated',
        'properties',
        'sort',
    ];

    protected $hidden = [
        'selectName',
        'isEnabled',
        'autoCreated',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'isEnabled' => 'boolean',
        'autoCreated' => 'boolean',
        'properties' => 'json',
    ];

    public static function labelToValue($name, $label)
    {
        $select = static::query()->where('selectName', $name)->first();

        return $select?->options()->where('label', $label)->first() ?? null;
    }

    public static function getOptions($name)
    {
        $select = static::query()->where('selectName', $name)->first();

        return $select?->options;
    }

    public function options(): HasMany
    {
        return $this->hasMany(SelectOption::class, 'selectName', 'selectName');
    }

    public function schemas(): Collection
    {
        return Select::with('options')->select('selectName', 'displayName', 'description')->get();
    }

    public static function retrieve($name): array
    {
        $select = static::with(['options' => function ($query) {
            $query->orderBy('sort');
        }])->where('selectName', $name)->first();
        $options = $select->options->groupBy('groupName');
        if (count($options) == 1) {
            $options = $select->options;
        } else {
            $options = [];
            foreach ($select->options->groupBy('groupName') as $name => $option) {
                $options[] = [
                    'label' => $name,
                    'options' => $option,
                ];
            }
        }

        return [
            'displayName' => $select['displayName'],
            'description' => $select['description'],
            'options' => $options,
        ];
    }
}
