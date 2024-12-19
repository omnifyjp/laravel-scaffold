<?php

namespace FammSupport\Models;

use FammSupport\Models\Traits\UseQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    public function options(): HasMany
    {
        return $this->hasMany(SelectOption::class, 'selectName', 'selectName');
    }

    public function schemas(): Collection
    {
        return Select::with('options')->select('selectName', 'displayName', 'description')->get();
    }

}
