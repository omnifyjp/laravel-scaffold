<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentMappingTarget extends Model
{
    use UseQuery;

    protected $table = '_document_mapping_targets';

    protected $primaryKey = 'id';

    const TYPE_ATTRIBUTE = 'ATTRIBUTE';

    protected $fillable = [
        'name',
        'type',
        'attributeName',
        'custom_data',
    ];

    protected $hidden = [

    ];

    protected $casts = [
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
