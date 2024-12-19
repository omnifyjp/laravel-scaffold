<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocumentMappingTarget extends Model
{
    use UseQuery;

    protected $table = '_generated_document_mapping_targets';

    protected $primaryKey = 'id';

    protected $with = ['mapping'];

    protected $fillable = [
        'name',
        'type',
        'attributeName',
        'mapping_type',
        'mapping_id',
    ];

    protected $hidden = [
        'updated_at',
        'created_at',
        'mapping_type',
        '_generated_document_id',
        'mapping_id',
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
