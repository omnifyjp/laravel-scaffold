<?php

namespace OmnifyJP\LaravelScaffold;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyBuildCommand;
use OmnifyJP\LaravelScaffold\Console\Commands\OmnifyPermissionSyncCommand;
use OmnifyJP\LaravelScaffold\Installers\ComposerConfigUpdater;
use OmnifyJP\LaravelScaffold\Models\PersonalAccessToken;
use OmnifyJP\LaravelScaffold\Services\Aws\DynamoDBService;
use OmnifyJP\LaravelScaffold\Services\Aws\SnsService;

class LaravelScaffoldServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            ComposerConfigUpdater::update();
        }
        $this->conditionallyRegisterProviders();

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        if (File::isDirectory(support_omnify_path('database/migrations'))) {
            $this->loadMigrationsFrom([
                support_omnify_path('database/migrations'),
            ]);
        }

        // Omnify専用のmigrationディレクトリを追加
        $omnifyMigrationsPath = database_path('migrations/omnify');
        if (File::isDirectory($omnifyMigrationsPath)) {
            $this->loadMigrationsFrom([$omnifyMigrationsPath]);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                OmnifyBuildCommand::class,
                OmnifyPermissionSyncCommand::class,
            ]);
        }
    }

    public function register(): void
    {

        $this->app->singleton(DynamoDBService::class, function (Application $app) {
            return new DynamoDBService;
        });

        $this->app->singleton(SnsService::class, function (Application $app) {
            return new SnsService;
        });

        // $this->app->singleton(Schema::class, function (Application $app) {
        //     return new Schema;
        // });

        // $this->loadRoutesFrom(__DIR__ . '/../routes/support.php');

        $this->app->singleton('omnifyjp.laravel-scaffold.version', function () {
            return $this->getPackageVersion();
        });
    }

    /**
     * Conditionally register service providers if they exist
     */
    protected function conditionallyRegisterProviders(): void
    {
        $providersConfig = [
            [
                'class' => \App\Omnify\Providers\OmnifyServiceProvider::class,
                'file' => app_path('Omnify/Providers/OmnifyServiceProvider.php'),
            ],
            [
                'class' => \App\Omnify\Providers\OmnifyRepositoryServiceProvider::class,
                'file' => app_path('Omnify/Providers/OmnifyRepositoryServiceProvider.php'),
            ],
        ];

        foreach ($providersConfig as $config) {
            if (file_exists($config['file']) && class_exists($config['class'])) {
                $this->app->register($config['class']);
            }
        }

        // Policies
        try {
            foreach (glob(app_path('Omnify/Policies').'/*.php') as $file) {
                $policyClass = 'App\\Omnify\\Policies\\'.basename($file, '.php');
                $modelClass = 'App\\Models\\'.Str::chopEnd(basename($file, '.php'), 'Policy');
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
