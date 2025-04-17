<?php

namespace FammSupport;

use Exception;
use FammSupport\Console\Commands\FammGenerateTypesCommand;
use FammSupport\Console\Commands\OmnifyInstallCommand;
use FammSupport\Console\Commands\OmnifyLoginCommand;
use FammSupport\Console\Commands\OmnifyProjectsCommand;
use FammSupport\Helpers\Schema;
use FammSupport\Models\PersonalAccessToken;
use FammSupport\Services\Aws\DynamoDBService;
use FammSupport\Services\Aws\SnsService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
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
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (File::exists(omnify_path('app/bootstrap.php'))) {
            require_once omnify_path('app/bootstrap.php');
        }
        $this->mergeConfigFrom(
            __DIR__ . '/../config/omnify.php', 'omnify'
        );


        try {
            foreach (glob(omnify_path('app/Policies') . '/*.php') as $file) {
                $policyClass = 'FammApp\\Policies\\' . basename($file, '.php');
                $modelClass = 'FammApp\\Models\\' . Str::chopEnd(basename($file, '.php'), 'Policy');
                if (class_exists($modelClass) && class_exists($policyClass)) {
                    Gate::policy($modelClass, $policyClass);
                    Gate::policy('\\' . $modelClass, '\\' . $policyClass);
                }
            }
        } catch (Exception $exception) {
        }


        if ($this->app->runningInConsole()) {
            $this->commands([
                OmnifyLoginCommand::class,
                OmnifyProjectsCommand::class,

                FammGenerateTypesCommand::class,
                OmnifyInstallCommand::class
            ]);
        }
    }

    /**
     */
    public function register(): void
    {
        $this->app->singleton(DynamoDBService::class, function (Application $app) {
            return new DynamoDBService();
        });

        $this->app->singleton(SnsService::class, function (Application $app) {
            return new SnsService();
        });

        $this->app->singleton(Schema::class, function (Application $app) {
            return new Schema();
        });

        $this->loadRoutesFrom(support_path('routes/support.php'));
    }
}
