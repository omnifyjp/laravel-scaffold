<?php

namespace FammSupport;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class DynamicSchemaProcessor
{
    private array $variableProcessors = [];

    public function __construct()
    {
        $this->registerDefaultProcessors();
    }

    private function registerDefaultProcessors(): void
    {
        $this->registerProcessor('routeParam', function ($key) {
            return Route::current()->parameter($key);
        });

        $this->registerProcessor('queryParam', function ($key) {
            return request()->query($key);
        });

        $this->registerProcessor('auth', function ($key) {
            return Auth::user()->$key ?? null;
        });

        $this->registerProcessor('config', function ($key) {
            return Config::get($key);
        });

        $this->registerProcessor('session', function ($key) {
            return Session::get($key);
        });

        $this->registerProcessor('request', function ($key) {
            return Request::input($key);
        });

        $this->registerProcessor('now', function ($format) {
            return now()->format($format);
        });

    }

    public function registerProcessor(string $type, callable $processor): void
    {
        $this->variableProcessors[$type] = $processor;
    }

    public function processVariable(string $value): array|string|null
    {
        $pattern = '/{{\$(\w+):(.+)}}/';
        if (!preg_match($pattern, $value, $matches)) {
            return $value;
        }
        $type = $matches[1];
        $key = $matches[2];
        if (!isset($this->variableProcessors[$type])) {
            return $value;
        }
        return preg_replace($pattern, $this->variableProcessors[$type]($key), $value);
    }

    public function processSchema(array $schema): array
    {
        array_walk_recursive($schema, function (&$value) {
            if (is_string($value)) {
                $value = $this->processVariable($value);
            }
        });

        return $schema;
    }

}