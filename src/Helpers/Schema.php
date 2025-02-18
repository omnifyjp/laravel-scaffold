<?php

namespace FammSupport\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class Schema
{
    const TYPE_ID = 'Id';

    const TYPE_STRING = 'String';

    const TYPE_ASSOCIATION = 'Association';

    const TYPE_LOOKUP = 'Lookup';

    const TYPE_TIMESTAMP = 'Timestamp';

    const TYPE_BIG_INT = 'BigInt';

    const TYPE_JAPAN_PERSON_NAME = 'JapanPersonName';

    const TYPE_SELECT = 'Select';

    const TYPE_EMAIL = 'Email';

    const TYPE_PASSWORD = 'Password';

    const TYPE_INT = 'Int';

    const TYPE_TEXT = 'Text';

    const TYPE_DATE = 'Date';

    const TYPE_JAPAN_ADDRESS = 'JapanAddress';

    const TYPE_ADDRESS = 'Address';

    const RELATION_MANY_TO_ONE = 'ManyToOne';

    const RELATION_MANY_TO_MANY = 'ManyToMany';

    const RELATION_ONE_TO_MANY = 'OneToMany';

    private Collection $schemas;

    public function __construct()
    {
        $schemas = collect();
        if (File::exists(famm_path('schema-lock.json'))) {
            $schemas = collect(json_decode(File::get(famm_path('schema-lock.json')), 1) ?? []);
        }
        $this->schemas = $schemas;
    }

    public function all(): Collection
    {
        return $this->schemas;
    }

    public function get($objectName): ?Collection
    {
        return collect($this->schemas[$objectName]) ?? null;
    }

    public function getTarget($objectName, $propertyName): ?Collection
    {
        $collection = $this->get($objectName);
        return collect($this->get($collection['properties'][$propertyName]['target'])) ?? null;
    }
}
