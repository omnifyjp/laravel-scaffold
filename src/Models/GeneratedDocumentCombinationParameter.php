<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocumentCombinationParameter extends Model
{
    use UseQuery;

    protected $table = '_generated_document_combination_parameters';

    protected $primaryKey = 'id';

    protected $with = ['mapping'];

    protected $fillable = [
        'name',
        'type',
        'attributeName',
        'combination_type',
        'combination_id',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
        'combination_type',
        '_generated_document_id',
        'combination_id',
    ];

    protected $casts = [
    ];

    public function generated_document(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, '_generated_document_id');
    }

    public function mapping(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('mapping');
    }
}
