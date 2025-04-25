<?php

/** @noinspection LaravelFunctionsInspection */

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

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
        $seed = $this->option('seed');
        $fresh = $this->option('fresh');
        $migrate = $this->option('migrate');


        $objects = $this->generateObjects();

        $url = OmnifyLoginCommand::ENDPOINT . '/api/schema-generate';

        $outputDir = omnify_path('.temp');
        $baseDir = omnify_path();
        File::makeDirectory($outputDir, 0755, true, true);
        $tempZipFile = omnify_path('.temp/temp.zip');

        try {
            $this->info('Processing...');
            $request = Http::timeout(600)
                ->acceptJson()
                ->withQueryParameters(['fresh' => $fresh])
                ->attach(
                    'schema',
                    json_encode($objects),
                    'schema.json'
                );

            if (file_exists(omnify_path('omnify.lock'))) {
                $request->attach(
                    'omnify-lock',
                    File::get(omnify_path('omnify.lock')),
                    'omnify.lock'
                );
            };

            $response = $request
                ->post($url);

            if ($response->failed()) {
                $body = json_decode($response->body(), 1);
                $this->error('Failed');
                $this->warn(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return;
            }

            // Save as temporary file
            File::put($tempZipFile, $response->body());
            $zip = new ZipArchive;
            if ($zip->open($tempZipFile) === true) {
                $zip->extractTo($outputDir);
                $zip->close();
                $this->info("Extraction completed: {$outputDir}");
            } else {
                $this->error('Could not open ZIP file.');

                return;
            }
            // Delete temporary file
            File::delete($tempZipFile);

            // Check and process filelist
            $fileListPath = $outputDir . '/filelist.json';
            if (!File::exists($fileListPath)) {
                $this->error('filelist.json not found.');

                return;
            }

            if ($fresh) {
                $this->info('Deleting files...');
                File::deleteDirectory(omnify_path('database'));
                File::deleteDirectory(omnify_path('app/Models/Base'));
                File::deleteDirectory(omnify_path('ts/Models/Base'));
            }

            // Move files to actual directory
            $this->moveFilesBasedOnFileList($fileListPath, $outputDir, $baseDir);

            File::deleteDirectory($outputDir);

            if ($migrate) {
                $output = new BufferedOutput;
                $this->info('Run migrate');

                Artisan::call($fresh ? 'migrate:fresh' : 'migrate', [
                    '--force' => true,
                    '--seed' => $seed,
                ], $output);
                $this->info($output->fetch());
            }

            $this->info('Process completed successfully!');

            return;
        } catch (\Exception $e) {
            $this->error('Error occurred: ' . $e->getMessage());

            // Delete temporary file if it exists
            if (File::exists($tempZipFile)) {
                File::delete($tempZipFile);
            }

            return;
        }
    }

    /**
     * Get the list of projects from the API
     */
    private function getProjects(): ?array
    {
        $authFile = omnify_path('.credentials');
        $content = File::get($authFile);
        $decoded = json_decode($content, true);
        $token = $decoded['token'] ?? '';

        // Remove timestamp for API request
        $tokenParts = explode('|', $token);
        array_pop($tokenParts);
        $accessToken = implode('|', $tokenParts);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get(OmnifyLoginCommand::ENDPOINT . '/api/projects');

            if ($response->successful()) {
                return $response->json()['data'] ?? $response->json();
            }

            $this->error('API Error: ' . ($response->json()['message'] ?? 'Unknown error'));

            return null;
        } catch (\Exception $e) {
            $this->error('Connection Error: ' . $e->getMessage());

            return null;
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

    private function generateObjects(): array
    {
        $objects = [];
        foreach ([database_path('schemas'), support_path('database/schemas')] as $_directory) {
            if (!File::exists($_directory)) {
                continue;
            }
            foreach (File::directories($_directory) as $directory) {
                foreach (File::allFiles($directory) as $file) {
                    if (File::exists($file->getRealPath()) && in_array($file->getExtension(), ['json', 'yaml', 'yml'])) {
                        $object = $file->getExtension() === 'json'
                            ? File::json($file)
                            : Yaml::parse(File::get($file));
                        $objectName = Str::chopEnd($file->getBasename(), '.' . $file->getExtension());
                        $objects[$objectName] = [
                            'objectName' => $objectName,
                            ...$object,
                        ];
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * Move files based on fileList
     */
    protected function moveFilesBasedOnFileList(string $fileListPath, string $sourceDir, string $targetDir): void
    {
        $fileListContent = File::get($fileListPath);
        $fileList = json_decode($fileListContent, true);

        if (!is_array($fileList)) {
            $this->error('Invalid format of filelist.json.');

            return;
        }

        $filesProcessed = 0;
        $filesSkipped = 0;

        foreach ($fileList as $fileInfo) {
            if (!isset($fileInfo['path']) || !isset($fileInfo['replace'])) {
                $this->warn('Invalid file information was skipped.');

                continue;
            }

            $sourcePath = $sourceDir . '/' . $fileInfo['path'];
            $targetPath = $targetDir . '/' . $fileInfo['path'];

            // Skip if file does not exist
            if (!File::exists($sourcePath)) {
                $this->warn('File not found: ' . $fileInfo['path']);

                continue;
            }

            // Create target directory
            $targetDirectory = dirname($targetPath);
            if (!File::exists($targetDirectory)) {
                File::makeDirectory($targetDirectory, 0755, true, true);
            }

            // Move files based on replace flag
            if ($fileInfo['replace'] || !File::exists($targetPath)) {
                File::copy($sourcePath, $targetPath, true);
                $filesProcessed++;
                $this->info('File copied: ' . $fileInfo['path']);
            } else {
                $filesSkipped++;
                $this->warn('File skipped: ' . $fileInfo['path']);
            }
        }
        $this->info("File processing completed: {$filesProcessed} processed, {$filesSkipped} skipped");
    }
}
