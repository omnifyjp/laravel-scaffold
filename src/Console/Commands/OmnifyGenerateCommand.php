<?php

/** @noinspection LaravelFunctionsInspection */

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use OmnifyJP\LaravelScaffold\OmnifyService;
use OmnifyJP\LaravelScaffold\Services\OmnifyGeneratorService;

class OmnifyGenerateCommand extends Command
{
    protected $signature = 'omnify:generate {--migrate : Run migrate}  {--seed : Run seeder}  {--fresh : Drops all tables and re-runs all migrations}';

    protected $description = 'Command description';

    // Path to the .project file
    protected string $projectFilePath;

    public function __construct()
    {
        parent::__construct();
        $this->projectFilePath = omnify_path('.project');
    }

    public function handle(): void
    {
        $this->displayHeader();

        $seed = $this->option('seed');
        $fresh = $this->option('fresh');
        $migrate = $this->option('migrate');

        // Initialize the generator service
        $generatorService = new OmnifyGeneratorService($this);

        $objects = $generatorService->generateObjects();

        $url = OmnifyService::ENDPOINT . '/api/schema-generate';

        try {
            $this->info('Processing...');

            // Create the HTTP request
            $request = $generatorService->createAuthenticatedRequest($url, $objects, $fresh);

//            $this->info('Connecting to Omnify API');
            $generatorService->showSpinner('  Establishing secure connection', 2);

            $response = $request->post($url);

            // Process the API response
            if (!$generatorService->processApiResponse($response)) {
                return;
            }

            // Check and process filelist
            $fileListPath = omnify_path('.temp/filelist.json');
            if (!File::exists($fileListPath)) {
                $this->error('filelist.json not found.');
                return;
            }

            if ($fresh) {
                $generatorService->cleanDirectoriesForFresh();
            }

            // Move files
            if (!$generatorService->moveFilesBasedOnFileList($fileListPath)) {
                return;
            }

            // Clean up
            $generatorService->cleanup();

            // Run migrations if needed
            if ($migrate) {
                $generatorService->runMigrations($fresh, $seed);
            }

            $this->info('Process completed successfully!');
            return;

        } catch (\Exception $e) {
            $this->error('Error occurred: ' . $e->getMessage());

            // Clean up in case of error
            $generatorService->cleanup();
            return;
        }
    }

    /**
     * Load project data from .project file
     */
    private function loadProjectData(): array
    {
        if (File::exists($this->projectFilePath)) {
            try {
                $content = File::get($this->projectFilePath);
                return json_decode($content, true) ?? [];
            } catch (\Exception $e) {
                $this->warn('Failed to read .project file: ' . $e->getMessage());
                return [];
            }
        }
        return [];
    }

    /**
     * Save project data to .project file
     */
    private function saveProjectData(array $data): void
    {
        try {
            $jsonContent = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            File::put($this->projectFilePath, $jsonContent);
            $this->info('Project data saved to .project file.');
        } catch (\Exception $e) {
            $this->error('Failed to save project data: ' . $e->getMessage());
        }
    }

    /**
     * Display a stylish header for the command
     *
     */
    private function displayHeader(): void
    {
        $version = app('omnifyjp.laravel-scaffold.version');
        $line = str_repeat('=', strlen("Omnify LaravelScaffold") + 29);

        $this->newLine();
        $this->line("<fg=blue>{$line}</>");
        $this->line("<fg=blue>===      </><fg=green>Omnify LaravelScaffold [{$version}]</><fg=blue>      ===</>");
        $this->line("<fg=blue>{$line}</>");
        $this->newLine();
    }
}
