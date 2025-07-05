<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use OmnifyJP\LaravelScaffold\OmnifyService;
use OmnifyJP\LaravelScaffold\Services\OmnifyGeneratorService;

class OmnifyInstallCommand extends Command
{
    protected $signature = 'omnify:sync {--migrate : Run migrate}  {--seed : Run seeder}  {--fresh : Drops all tables and re-runs all migrations}';

    protected $description = 'Command description';

    protected string $projectFilePath;

    public function __construct()
    {
        parent::__construct();
        $this->projectFilePath = omnify_path('.project');
    }

    /**
     * @throws FileNotFoundException
     */
    public function handle(): void
    {
        $this->displayHeader('Omnify Sync');

        if (! OmnifyService::verify()) {
            $this->error('No valid authentication token found. Please run omnify:login to login.');

            return;
        }

        $seed = $this->option('seed');
        $fresh = $this->option('fresh');
        $migrate = $this->option('migrate');

        // Load project data from .project file if it exists
        $projectData = $this->loadProjectData();
        $omnify_key = $projectData['omnify_key'] ?? null;
        $omnify_secret = $projectData['omnify_secret'] ?? null;

        // Check if project data exists
        if (! $omnify_key) {
            $this->warn('Project data not found.');

            // Get project list and let user select one
            $projects = OmnifyService::getProjects();

            if (empty($projects)) {
                $this->error('Failed to retrieve projects or no projects available.');

                return;
            }

            // Format projects for selection
            $choices = [];
            foreach ($projects as $index => $project) {
                $choices[$index + 1] = $project['code'].' - '.$project['name'];
            }

            // Let user select a project
            $selectedIndex = $this->choice('Select a project to use', $choices);
            $selectedProjectIndex = array_search($selectedIndex, $choices);
            $selectedProject = $projects[$selectedProjectIndex - 1];

            // Set the selected project's code and secret
            $omnify_key = $selectedProject['code'];
            $omnify_secret = $selectedProject['secret'];

            // Save project data to .project file
            $this->saveProjectData([
                'omnify_key' => $omnify_key,
                'omnify_secret' => $omnify_secret,
                'project_name' => $selectedProject['name'],
            ]);

            $this->info("Project set to: {$selectedProject['name']} ({$omnify_key})");
        } elseif (! $omnify_secret) {
            $this->error('Project secret not found in .project file.');

            return;
        }

        // Initialize the generator service
        $generatorService = new OmnifyGeneratorService($this);

        $objects = $generatorService->generateObjects();

        $url = OmnifyService::getEndpoint().'/api/schema-generator/'.$omnify_key;

        try {
            $this->info('Processing...');

            // Create the HTTP request with project secret
            $request = $generatorService->createProjectRequest($url, $objects, $fresh, $omnify_secret);

            $this->info('Connecting to Omnify API');
            $generatorService->showSpinner('  Establishing secure connection', 2);

            $response = $request->post($url);

            // Process the API response
            if (! $generatorService->processApiResponse($response)) {
                return;
            }

            // Check and process filelist
            $filelistPath = omnify_path('.temp/filelist.json');
            if (! File::exists($filelistPath)) {
                $this->error('filelist.json not found.');

                return;
            }

            if ($fresh) {
                $generatorService->cleanDirectoriesForFresh();
            }

            // Move files
            if (! $generatorService->moveFilesBasedOnFileList($filelistPath)) {
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
            $this->displayFriendlyError($e);

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
                $this->warn('Failed to read .project file: '.$e->getMessage());

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
            $this->error('Failed to save project data: '.$e->getMessage());
        }
    }

    /**
     * Display a stylish header for the command
     */
    private function displayHeader(string $title): void
    {
        $version = '1.0.0';
        $line = str_repeat('=', strlen($title) + 20);

        $this->newLine();
        $this->line("<fg=blue>{$line}</>");
        $this->line("<fg=blue>===      </><fg=green>{$title} v{$version}</><fg=blue>      ===</>");
        $this->line("<fg=blue>{$line}</>");
        $this->newLine();
    }

    /**
     * Display a user-friendly error message for exceptions
     */
    private function displayFriendlyError(\Exception $exception): void
    {
        $this->newLine();
        $this->error('❌ An error occurred during the sync process');
        $this->newLine();

        // Check if it's an HTTP exception and provide specific guidance
        if (str_contains($exception->getMessage(), 'cURL error') || str_contains($exception->getMessage(), 'timeout')) {
            $this->line('  <fg=red>Connection Issue:</> Unable to connect to Omnify API');
            $this->line('  <fg=yellow>Possible causes:</> ');
            $this->line('    • Network connectivity issues');
            $this->line('    • API server is temporarily unavailable');
            $this->line('    • Firewall blocking the connection');
            $this->newLine();
            $this->line('  <fg=cyan>Try:</> ');
            $this->line('    • Check your internet connection');
            $this->line('    • Wait a few minutes and try again');
            $this->line('    • Contact support if the issue persists');
        } elseif (str_contains($exception->getMessage(), 'Unauthenticated') || str_contains($exception->getMessage(), '401')) {
            $this->line('  <fg=red>Authentication Issue:</> Invalid or expired token');
            $this->newLine();
            $this->line('  <fg=cyan>Try:</> ');
            $this->line('    • Run: <fg=green>php artisan omnify:login</> to re-authenticate');
        } elseif (str_contains($exception->getMessage(), 'json')) {
            $this->line('  <fg=red>Data Format Issue:</> Problem parsing response data');
            $this->line('  <fg=yellow>This might be a temporary server issue</> ');
            $this->newLine();
            $this->line('  <fg=cyan>Try:</> ');
            $this->line('    • Wait a few minutes and try again');
            $this->line('    • Check if your schema files are valid');
        } else {
            $this->line('  <fg=red>Error:</> '.$exception->getMessage());
        }

        $this->newLine();
        $this->line('  <fg=gray>For technical details, use -v flag for verbose output</> ');

        // Show full exception details only in verbose mode
        if ($this->getOutput()->isVerbose()) {
            $this->newLine();
            $this->line('  <fg=gray>Technical Details:</> ');
            $this->line('  <fg=gray>Exception:</> '.get_class($exception));
            $this->line('  <fg=gray>File:</> '.$exception->getFile().':'.$exception->getLine());
            if ($this->getOutput()->isVeryVerbose()) {
                $this->newLine();
                $this->line('  <fg=gray>Stack Trace:</> ');
                $this->line($exception->getTraceAsString());
            }
        }
    }
}
