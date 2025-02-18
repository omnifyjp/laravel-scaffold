<?php

namespace FammSupport\Models\Traits;

use FammSupport\Helpers\Schema;
use Illuminate\Database\Eloquent\Builder;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

trait UseQuery
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function schema()
    {
        return app(Schema::class)->get(class_basename(static::class));
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function builder($condition = []): Builder
    {
        $builder = static::with(static::getWiths())
            ->withCount(static::getWithCounts());
        static::addSearchCondition($builder, $condition);

        return $builder;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getWithCounts(): array
    {
        $withs = [];
        $schema = static::schema();

        foreach ($schema['properties'] as $item) {
            if ($item['type'] == Schema::TYPE_ASSOCIATION) {
                $withs[$item['propertyName']] = function ($query) {
                };
            }
        }

        return $withs;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getWiths(): array
    {
        $withs = [];
        $schema = static::schema();
        foreach ($schema['properties'] as $item) {
            if ($item['type'] == Schema::TYPE_SELECT) {
                $withs[$item['propertyName']] = function ($query) {
                };
            } elseif ($item['type'] == Schema::TYPE_LOOKUP) {
                $withs[$item['propertyName']] = function ($query) {
                };
            }
            if (isset($item['fields']) && is_array($item['fields'])) {
                foreach ($item['fields'] as $field) {
                    if ($field['type'] == Schema::TYPE_SELECT) {
                        $withs[$field['propertyName']] = function ($query) {
                        };
                    }
                }
            }

        }

        return $withs;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function addSearchCondition(Builder $builder, $condition = []): void
    {
        $keyword = $condition['q'] ?? null;
        $schema = static::schema();

        if ($keyword) {
            $builder->where(function (Builder $query) use ($keyword, $schema) {
                $titleIndex = $schema['titleIndex'] ?? null;
                if ($titleIndex && $attr = $schema['properties'][$titleIndex]) {
                    if (isset($attr['fields']) && is_array($attr['fields'])) {
                        foreach ($attr['fields'] as $field) {
                            $query->orWhere($field['propertyName'], 'like', "%$keyword%");
                        }
                    } else {
                        $query->orWhere($titleIndex, 'like', "%$keyword%");
                    }
                }
            });
        }
    }
}
