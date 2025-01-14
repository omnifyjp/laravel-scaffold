<?php

namespace FammSupport;

use FammApp\Schema;
use FammApp\View;
use FammSupport\Services\Aws\SnsService;
use FammSupport\Services\Aws\DynamoDBService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class FammSupportServiceProvider extends ServiceProvider
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        if (File::exists(famm_path('app/bootstrap.php'))) {
            require_once famm_path('app/bootstrap.php');
        }

        $this->commands([]);
    }

    /**
     */
    public function register(): void
    {
        if (class_exists('\FammApp\View')) {
            $this->app->singleton(View::class, function (Application $app) {
                return new View();
            });
        }

        $this->app->singleton(DynamoDBService::class, function (Application $app) {
            return new DynamoDBService();
        });

        $this->app->singleton(SnsService::class, function (Application $app) {
            return new SnsService();
        });


        if (class_exists('\FammApp\Schema')) {
            $this->app->singleton(Schema::class, function (Application $app) {
                return new Schema();
            });
        }

        $this->loadRoutesFrom(famm_path('routes/api-collection.php'));

    }
}
