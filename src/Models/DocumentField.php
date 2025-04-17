<?php

namespace OmnifyJP\LaravelScaffold\Models;

use OmnifyJP\LaravelScaffold\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $generation_type
 * @property string $base_collection
 */
class DocumentField extends Model
{
    use UseQuery;

    const ACTION_TYPE_ACTION_TYPE_REPLACE = 'REPLACE';

    const ACTION_TYPE_ACTION_TYPE_VISIBILITY = 'VISIBILITY';

    const KIND_TEXT = 'TEXT';

    const KIND_IMAGE = 'IMAGE';

    protected $primaryKey = 'id';

    protected $table = '_document_fields';

    protected $fillable = [
        'type',
        'name',
        'coordinate',
        'combination_variable',
        'combination_formula',
        'kind',
        'action_type',
    ];

    protected $hidden = [
    ];

    protected $casts = [
        'properties' => 'json',
    ];
}
