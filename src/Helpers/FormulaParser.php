<?php

namespace OmnifyJP\LaravelScaffold\Helpers;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class FormulaParser
{
    protected mixed $record;

    private mixed $datasource;

    public function __construct($record = null, $datasource = null)
    {
        $this->record = $record;
        $this->datasource = $datasource ?? collect();
    }

    /**
     * @throws Exception
     */
    public function parse(string $formula)
    {
        preg_match('/(\w+)\((.*?)\)/', $formula, $matches);

        if (empty($matches)) {
            return null;
        }

        $function = $matches[1];
        $params = $this->parseParams($matches[2]);

        return $this->executeFunction($function, $params);
    }

    protected function parseParams($paramsString): array
    {
        $params = explode(',', $paramsString);

        return array_map(function ($param) {
            $param = trim($param);
            if ($param === '$record') {
                return $this->record;
            }
            if (preg_match('/^\$(\w+)(.\w+)+$/', $param, $matches)) {
                $path = substr($param, 1);

                return $this->getValueFromPath($path, $this->datasource);
            }

            if (is_numeric($param)) {
                return (float) $param;
            }

            return trim($param, '"\'');
        }, $params);
    }

    /**
     * @throws Exception
     */
    protected function executeFunction($function, $params)
    {
        return match (strtoupper($function)) {
            'YEAR' => Carbon::parse($params[0])->year,
            'MONTH' => Carbon::parse($params[0])->month,
            'DAY' => Carbon::parse($params[0])->day,
            'DATE' => Carbon::createFromDate($params[0], $params[1], $params[2])->format('Y-m-d'),
            'NOW' => Carbon::now(),
            'DATEADD' => Carbon::parse($params[0])->add($params[2], $params[1]),
            'DATEDIFF' => Carbon::parse($params[0])->diffInDays(Carbon::parse($params[1])),
            'ROUND' => round($params[0], $params[1] ?? 0),
            'FLOOR' => floor($params[0]),
            'CEIL' => ceil($params[0]),
            'ABS' => abs($params[0]),
            'MAX' => max($params),
            'MIN' => min($params),
            'SUM' => array_sum($params),
            'AVG' => array_sum($params) / count($params),
            'POWER' => pow($params[0], $params[1]),
            'LEFT' => Str::substr($params[0], 0, $params[1]),
            'RIGHT' => Str::substr($params[0], -$params[1]),
            'LEN' => Str::length($params[0]),
            'LOWER' => Str::lower($params[0]),
            'UPPER' => Str::upper($params[0]),
            'TRIM' => trim($params[0]),
            'CONCAT' => implode('', $params),
            'REPLACE' => str_replace($params[1], $params[2], $params[0]),
            'SUBSTRING' => Str::substr($params[0], $params[1], $params[2] ?? null),
            'IF' => $params[0] ? $params[1] : $params[2],
            'ISEMPTY' => empty($params[0]),
            'ISNULL' => is_null($params[0]),
            'ISNUMBER' => is_numeric($params[0]),
            default => throw new \Exception("Function {$function} not supported"),
        };
    }

    private function getValueFromPath($path, $data)
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_null($current)) {
                return null;
            }
            $current = $current[$key] ?? null;
        }

        return $current;
    }
}
