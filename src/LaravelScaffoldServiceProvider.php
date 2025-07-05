<?php

namespace OmnifyJP\LaravelScaffold;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OmnifyJP\LaravelScaffold\Console\Commands\InstallCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyGenerateCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyGenerateTypesCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyInstallCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyLoginCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyProjectCreateCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyProjectsCommand;
use OmnifyJP\LaravelScaffold\Helpers\Schema;
use OmnifyJP\LaravelScaffold\Installers\ComposerConfigUpdater;
use OmnifyJP\LaravelScaffold\Models\PersonalAccessToken;
use OmnifyJP\LaravelScaffold\Services\Aws\DynamoDBService;
use OmnifyJP\LaravelScaffold\Services\Aws\SnsService;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

class LaravelScaffoldServiceProvider extends ServiceProvider
{
    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            ComposerConfigUpdater::update();
        }
        $this->conditionallyRegisterProviders();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (File::exists(omnify_path('app/bootstrap.php'))) {
            require_once omnify_path('app/bootstrap.php');
        }

        if (File::isDirectory(omnify_path('database/migrations'))) {
            $this->loadMigrationsFrom([
                omnify_path('database/migrations'),
            ]);
        }

        // Omnify専用のmigrationディレクトリを追加
        $omnifyMigrationsPath = database_path('migrations/omnify');
        if (File::isDirectory($omnifyMigrationsPath)) {
            $this->loadMigrationsFrom([$omnifyMigrationsPath]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                OmnifyLoginCommand::class,
                OmnifyProjectsCommand::class,
                OmnifyProjectCreateCommand::class,
                OmnifyGenerateTypesCommand::class,
                OmnifyInstallCommand::class,
                OmnifyGenerateCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
            ]);
        }

        $this->app->singleton(DynamoDBService::class, function (Application $app) {
            return new DynamoDBService;
        });

        $this->app->singleton(SnsService::class, function (Application $app) {
            return new SnsService;
        });

        $this->app->singleton(Schema::class, function (Application $app) {
            return new Schema;
        });

        $this->loadRoutesFrom(support_path('routes/support.php'));

        $this->app->singleton('omnifyjp.laravel-scaffold.version', function () {
            return $this->getPackageVersion();
        });

    }

    /**
     * Conditionally register service providers if they exist
     */
    protected function conditionallyRegisterProviders(): void
    {
        $providers = [
            \FammApp\Providers\ServiceProvider::class,
            \FammApp\Providers\RepositoryServiceProvider::class,
        ];

        foreach ($providers as $provider) {
            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }

        // Policies
        try {
            foreach (glob(omnify_path('app/Policies').'/*.php') as $file) {
                $policyClass = 'FammApp\\Policies\\'.basename($file, '.php');
                $modelClass = 'FammApp\\Models\\'.Str::chopEnd(basename($file, '.php'), 'Policy');
                if (class_exists($modelClass) && class_exists($policyClass)) {
                    Gate::policy($modelClass, $policyClass);
                    Gate::policy('\\'.$modelClass, '\\'.$policyClass);
                }
            }
        } catch (Exception $exception) {
        }

    }

    /**
     * Get package version from composer.json
     */
    protected function getPackageVersion(): string
    {
        $packagePath = dirname(__DIR__, 2);
        $composerFile = $packagePath.'/composer.json';

        if (file_exists($composerFile)) {
            $composerData = json_decode(file_get_contents($composerFile), true);
            if (isset($composerData['version'])) {
                return $composerData['version'];
            }
        }

        $composerLock = base_path('composer.lock');
        if (file_exists($composerLock)) {
            $lockData = json_decode(file_get_contents($composerLock), true);

            if (isset($lockData['packages'])) {
                foreach ($lockData['packages'] as $package) {
                    if (isset($package['name']) && $package['name'] === 'omnifyjp/laravel-scaffold') {
                        return $package['version'] ?? 'unknown';
                    }
                }
            }
        }

        return 'unknown';
    }
}
