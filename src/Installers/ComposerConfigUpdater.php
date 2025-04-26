<?php

namespace OmnifyJP\LaravelScaffold\Installers;

use Illuminate\Support\Facades\File;

class ComposerConfigUpdater
{
    /**
     * Update the composer.json file with required namespaces and providers
     *
     * @param bool $verbose Whether to output verbose information
     * @return array Status information
     */
    public static function update(bool $verbose = false): array
    {
        $status = [
            'success' => true,
            'messages' => [],
            'changes' => []
        ];

        $composerFile = base_path('composer.json');
        if (!file_exists($composerFile)) {
            $status['success'] = false;
            $status['messages'][] = 'composer.json not found.';
            return $status;
        }

        // Load composer.json content
        $composerJson = json_decode(file_get_contents($composerFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $status['success'] = false;
            $status['messages'][] = 'Invalid composer.json format.';
            return $status;
        }

        // Add providers
        $providers = [
            '\FammApp\Providers\ServiceProvider::class',
            '\FammApp\Providers\RepositoryServiceProvider::class'
        ];

        $changed = false;

        // Update providers
        if (!isset($composerJson['extra']['laravel']['providers'])) {
            $composerJson['extra']['laravel']['providers'] = [];
        }

        foreach ($providers as $provider) {
            if (!in_array($provider, $composerJson['extra']['laravel']['providers'])) {
                $composerJson['extra']['laravel']['providers'][] = $provider;
                $changed = true;
                $status['changes'][] = "Added provider: {$provider}";
            }
        }

        // Update autoload PSR-4
        if (!isset($composerJson['autoload']['psr-4'])) {
            $composerJson['autoload']['psr-4'] = [];
        }

        $namespaces = [
            'FammApp\\' => '.famm/app/',
            'FammApp\\Database\\Factories\\' => '.famm/database/factories/',
            'FammApp\\Database\\Seeders\\' => '.famm/database/seeders/'
        ];

        foreach ($namespaces as $namespace => $path) {
            if (!isset($composerJson['autoload']['psr-4'][$namespace])) {
                $composerJson['autoload']['psr-4'][$namespace] = $path;
                $changed = true;
                $status['changes'][] = "Added namespace: {$namespace} => {$path}";
            }
        }

        // Save changes if needed
        if ($changed) {
            file_put_contents(
                $composerFile,
                json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            $status['messages'][] = 'composer.json updated successfully.';
            $status['messages'][] = 'Run "composer dump-autoload" to apply changes.';
        } else {
            $status['messages'][] = 'No changes needed in composer.json';
        }

        // Create base directories
        self::createBaseDirectories($status);

        // Create placeholder providers
        self::createPlaceholderProviders($status);

        return $status;
    }

    /**
     * Create necessary base directories
     *
     * @param array $status Status information to update
     * @return void
     */
    protected static function createBaseDirectories(array &$status): void
    {
        $directories = [
            '.famm/app/Providers',
            '.famm/database/factories',
            '.famm/database/seeders'
        ];

        foreach ($directories as $directory) {
            $path = base_path($directory);
            if (!is_dir($path)) {
                if (!File::makeDirectory($path, 0755, true, true)) {
                    $status['messages'][] = "Failed to create directory: {$directory}";
                } else {
                    $status['changes'][] = "Created directory: {$directory}";
                }
            }
        }
    }

    /**
     * Create placeholder provider files to avoid errors before schema build
     *
     * @param array $status Status information to update
     * @return void
     */
    protected static function createPlaceholderProviders(array &$status): void
    {
        $providersPath = base_path('.famm/app/Providers');

        // Service Provider
        $serviceProviderPath = $providersPath . '/ServiceProvider.php';
        if (!file_exists($serviceProviderPath)) {
            $content = <<<'PHP'
<?php

namespace FammApp\Providers;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // This file is a placeholder and will be overwritten by FAMM schema builder
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
PHP;
            if (File::put($serviceProviderPath, $content)) {
                $status['changes'][] = "Created placeholder ServiceProvider";
            }
        }

        // Repository Service Provider
        $repoProviderPath = $providersPath . '/RepositoryServiceProvider.php';
        if (!file_exists($repoProviderPath)) {
            $content = <<<'PHP'
<?php

namespace FammApp\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // This file is a placeholder and will be overwritten by FAMM schema builder
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
PHP;
            if (File::put($repoProviderPath, $content)) {
                $status['changes'][] = "Created placeholder RepositoryServiceProvider";
            }
        }
    }
}
