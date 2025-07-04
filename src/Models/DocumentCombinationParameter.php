<?php

namespace OmnifyJP\LaravelScaffold\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OmnifyJP\LaravelScaffold\Models\Traits\UseQuery;

class DocumentCombinationParameter extends Model
{
    use UseQuery;

    protected $table = '_document_combination_parameters';

    protected $primaryKey = 'id';

    const TYPE_ATTRIBUTE = 'ATTRIBUTE';

    protected $fillable = [
        'name',
        'type',
        'propertyName',
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
