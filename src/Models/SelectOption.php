<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;

class SelectOption extends Model
{
    use UseQuery;

    protected $table = '_select_options';

    protected $fillable = [
        'selectName',
        'displayName',
        'value',
        'label',
        'description',
        'isEnabled',
        'autoCreated',
    ];

    protected $hidden = [
//        'id',
        'pivot',
        'selectName',
        'autoCreated',
        'isEnabled',
        'description',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'isDefault' => 'boolean',
        'disabled' => 'boolean',
        'extra' => 'json',
        'icon' => 'json',
        'properties' => 'json',
    ];

    public static function options($selectName)
    {
        return static::query()->where('selectName', $selectName)->get();
    }

    public static function option($selectName, $value)
    {
        return static::query()
            ->where('selectName', $selectName)
            ->where('value', $value)
            ->first();
    }

}
