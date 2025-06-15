<?php

namespace OmnifyJP\LaravelScaffold\Services;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OmnifyJP\LaravelScaffold\OmnifyService;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class OmnifyGeneratorService
{
    /**
     * @var Command
     */
    protected Command $command;

    /**
     * @var string
     */
    protected string $baseDir;

    /**
     * @var string
     */
    protected string $outputDir;

    /**
     * @var string
     */
    protected string $tempZipFile;

    /**
     * @var array
     */
    protected array $spinnerFrames = [
        "⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"
    ];

    /**
     * @var array
     */
    protected array $networkFrames = [
        "⢎⡰ ⢎⡱ ⢎⡱", "⢎⡱ ⢎⡱ ⢎⡰", "⢎⡱ ⢎⡰ ⢎⡰",
        "⢎⡡ ⢎⡱ ⢎⡱", "⢎⡡ ⢎⡡ ⢎⡱", "⡎⢱ ⢎⡡ ⢎⡡"
    ];

    /**
     * OmnifyGeneratorService constructor.
     *
     * @param Command $command
     */
    public function __construct(Command $command)
    {
        $this->command = $command;
        $this->baseDir = omnify_path();
        $this->outputDir = omnify_path('.temp');
        $this->tempZipFile = omnify_path('.temp/temp.zip');

        // Ensure output directory exists
        File::makeDirectory($this->outputDir, 0755, true, true);
    }

    /**
     * Generate objects from schema files
     *
     * @return array
     */
    public function generateObjects(): array
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
     * Process the API response and extract files
     *
     * @param mixed $response
     * @return bool
     */
    public function processApiResponse($response): bool
    {
        if ($response->failed()) {
            $this->displayFormattedError($response);
            return false;
        }

        $this->command->info('Generating schema package');
        $this->showSpinner('  Fetching response data', 2);

        // Save as temporary file
        File::put($this->tempZipFile, $response->body());

        $progressBar = $this->command->getOutput()->createProgressBar(100);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
        $progressBar->start();

        // Simulate download progress
        for ($i = 0; $i < 100; $i += 5) {
            usleep(50000); // 0.05 second delay
            $progressBar->advance(5);
        }

        $progressBar->finish();
        $this->command->newLine(2);

        $this->command->info('Extracting files');
        $this->showSpinner('  Preparing archive', 1);

        $zip = new ZipArchive;
        if ($zip->open($this->tempZipFile) === true) {
            $numFiles = $zip->numFiles;
            $progressBar = $this->command->getOutput()->createProgressBar($numFiles);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
            $progressBar->start();

            // Extract each file with progress
            for ($i = 0; $i < $numFiles; $i++) {
                $zip->extractTo($this->outputDir, [$zip->getNameIndex($i)]);
                $progressBar->advance();
                usleep(10000); // Small delay for visual effect
            }

            $zip->close();
            $progressBar->finish();
            $this->command->newLine(2);
            $this->command->info("✓ Extraction completed successfully");
        } else {
            $this->command->error('Could not open ZIP file.');
            return false;
        }

        // Delete temporary file
        File::delete($this->tempZipFile);

        return true;
    }

    /**
     * Display a formatted, user-friendly error message
     *
     * @param mixed $response
     * @return void
     */
    private function displayFormattedError($response): void
    {
        $body = json_decode($response->body(), true);
        
        $this->command->newLine();
        $this->command->error('❌ API Request Failed');
        $this->command->newLine();
        
        // Display HTTP status
        $statusCode = $response->status();
        $this->command->line("  <fg=red>HTTP Status:</> {$statusCode}");
        
        if (is_array($body)) {
            // Display main error message
            if (isset($body['message'])) {
                $this->command->line("  <fg=red>Message:</> {$body['message']}");
            }
            
            // Display detailed errors if available
            if (isset($body['errors']) && is_array($body['errors'])) {
                $this->command->newLine();
                $this->command->line("  <fg=yellow>Details:</>");
                foreach ($body['errors'] as $field => $errors) {
                    if (is_array($errors)) {
                        foreach ($errors as $error) {
                            $this->command->line("    <fg=red>•</> {$field}: {$error}");
                        }
                    } else {
                        $this->command->line("    <fg=red>•</> {$field}: {$errors}");
                    }
                }
            }
            
            // Display validation errors if available
            if (isset($body['error']) && is_string($body['error'])) {
                $this->command->line("  <fg=red>Error:</> {$body['error']}");
            }
            
            // Display additional data if available (but formatted)
            if (isset($body['data']) && is_array($body['data'])) {
                $this->command->newLine();
                $this->command->line("  <fg=yellow>Additional Information:</>");
                foreach ($body['data'] as $key => $value) {
                    if (is_string($value) || is_numeric($value)) {
                        $this->command->line("    <fg=cyan>{$key}:</> {$value}");
                    }
                }
            }
        } else {
            // If response is not JSON, display raw response
            $rawBody = $response->body();
            if (!empty($rawBody)) {
                $this->command->line("  <fg=red>Response:</> {$rawBody}");
            }
        }
        
        $this->command->newLine();
        $this->command->line("  <fg=gray>For more technical details, use -v flag</>");
        
        // Show raw JSON only in verbose mode
        if ($this->command->getOutput()->isVerbose() && is_array($body)) {
            $this->command->newLine();
            $this->command->line("  <fg=gray>Raw Response:</>");
            $this->command->line("  " . json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Clean up directories for fresh installation
     */
    public function cleanDirectoriesForFresh(): void
    {
        $this->command->info('Preparing for fresh installation');
        $this->showSpinner('  Cleaning existing files', 2);

        File::deleteDirectory(omnify_path('database'));
        File::deleteDirectory(omnify_path('app/Models/Base'));
        File::deleteDirectory(omnify_path('ts/Models/Base'));

        $this->command->info('✓ Directory cleanup completed');
    }

    /**
     * Move files based on file list
     *
     * @param string $fileListPath
     * @return bool
     * @throws FileNotFoundException
     */
    public function moveFilesBasedOnFileList(string $fileListPath): bool
    {
        $fileListContent = File::get($fileListPath);
        $fileList = json_decode($fileListContent, true);

        if (!is_array($fileList)) {
            $this->command->error('Invalid format of filelist.json.');
            return false;
        }

        $totalFiles = count($fileList);
        $this->command->info("Preparing for installation");
        $this->showSpinner("  Analyzing file structure", 2);

        $this->command->info("Installing {$totalFiles} files");

        $progressBar = $this->command->getOutput()->createProgressBar($totalFiles);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $filesProcessed = 0;
        $filesSkipped = 0;
        $fileDetails = [];

        foreach ($fileList as $fileInfo) {
            if (!isset($fileInfo['path']) || !isset($fileInfo['replace'])) {
                $fileDetails[] = ['status' => 'warn', 'message' => 'Invalid file information was skipped.'];
                $progressBar->advance();
                continue;
            }

            $sourcePath = $this->outputDir . '/' . $fileInfo['path'];
            $targetPath = $this->baseDir . '/' . $fileInfo['path'];

            // Skip if file does not exist
            if (!File::exists($sourcePath)) {
                $fileDetails[] = ['status' => 'warn', 'message' => 'File not found: ' . $fileInfo['path']];
                $progressBar->advance();
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
                $fileDetails[] = ['status' => 'info', 'message' => 'File installed: ' . $fileInfo['path']];
            } else {
                $filesSkipped++;
                $fileDetails[] = ['status' => 'warn', 'message' => 'File skipped: ' . $fileInfo['path']];
            }

            $progressBar->advance();
            usleep(5000); // Small delay for visual effect
        }

        $progressBar->finish();
        $this->command->newLine(2);

        // Show summary statistics
        $this->command->info("✓ Installation completed successfully");
        $this->command->info("  - {$filesProcessed} files installed");
        $this->command->info("  - {$filesSkipped} files skipped");

        // Only show detailed file information if verbosity is set higher
        if ($this->command->getOutput()->isVerbose()) {
            $this->command->newLine();
            $this->command->info("Detailed file information:");
            foreach ($fileDetails as $detail) {
                if ($detail['status'] === 'info') {
                    $this->command->info('  ' . $detail['message']);
                } else {
                    $this->command->warn('  ' . $detail['message']);
                }
            }
        }

        return true;
    }

    /**
     * Run migrations if needed
     *
     * @param bool $fresh
     * @param bool $seed
     */
    public function runMigrations(bool $fresh, bool $seed): void
    {
        $migrationType = $fresh ? 'fresh database migration' : 'database migration';
        $seedingMsg = $seed ? ' with seeding' : '';

        $this->command->newLine();
        $this->command->info("🔄 Running {$migrationType}{$seedingMsg}...");
        $this->command->newLine();

        $output = new BufferedOutput;

        Artisan::call($fresh ? 'migrate:fresh' : 'migrate', [
            '--force' => true,
            '--seed' => $seed,
        ], $output);

        $outputText = $output->fetch();

        // Add colored prefix to each line of output
        $lines = explode("\n", $outputText);
        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                $this->command->line("  <fg=blue>│</> " . $line);
            }
        }

        $this->command->newLine();
        $this->command->info("✓ Migration completed successfully");
    }

    /**
     * Clean up temporary files
     */
    public function cleanup(): void
    {
        if (File::exists($this->tempZipFile)) {
            File::delete($this->tempZipFile);
        }

        if (File::exists($this->outputDir)) {
            File::deleteDirectory($this->outputDir);
        }
    }

    /**
     * Display an animated spinner with message while a task is processing
     *
     * @param string $message
     * @param int $seconds
     * @return void
     */
    public function showSpinner(string $message, int $seconds = 3): void
    {
        $startTime = time();
        $frameCount = count($this->spinnerFrames);
        $i = 0;

        // Show spinner for specified seconds
        while (time() - $startTime < $seconds) {
            $frame = $this->spinnerFrames[$i % $frameCount];
            $this->command->getOutput()->write("\r<fg=blue>{$frame}</> {$message}...");
            usleep(100000); // 0.1 second
            $i++;
        }

        // Clear the spinner line
        $this->command->getOutput()->write("\r" . str_repeat(" ", strlen($message) + 10) . "\r");
    }

    /**
     * Display an animated ellipsis while waiting
     *
     * @param string $message
     * @param int $seconds
     * @return void
     */
    public function showWaitingDots(string $message, int $seconds = 3): void
    {
        $startTime = time();
        $dotFrames = ["", ".", "..", "..."];
        $i = 0;
        $frameCount = count($dotFrames);

        // Show spinner for specified seconds
        while (time() - $startTime < $seconds) {
            $dots = $dotFrames[$i % $frameCount];
            $this->command->getOutput()->write("\r<fg=yellow>{$message}{$dots}</>");
            usleep(300000); // 0.3 second
            $i++;

            // Clear the line
            $this->command->getOutput()->write("\r" . str_repeat(" ", strlen($message) + 5));
        }

        // Clear the line
        $this->command->getOutput()->write("\r" . str_repeat(" ", strlen($message) + 5) . "\r");
    }

    /**
     * Create HTTP request with auth token
     *
     * @param string $url
     * @param array $objects
     * @param bool $fresh
     * @return PendingRequest
     */
    public function createAuthenticatedRequest(string $url, array $objects, bool $fresh): PendingRequest
    {
        $request = Http::timeout(600)
            ->acceptJson()
            ->withQueryParameters(['fresh' => $fresh])
            ->attach(
                'schema',
                json_encode($objects),
                'schema.json'
            );

        if (File::exists(omnify_path('omnify.lock'))) {
            $request->attach(
                'omnify-lock',
                File::get(omnify_path('omnify.lock')),
                'omnify.lock'
            );
        }

        return $request;
    }

    /**
     * Create HTTP request with project secret
     *
     * @param string $url
     * @param array $objects
     * @param bool $fresh
     * @param string $projectSecret
     * @return PendingRequest
     */
    public function createProjectRequest(string $url, array $objects, bool $fresh, string $projectSecret): PendingRequest
    {
        $request = Http::timeout(600)
            ->acceptJson()
            ->withQueryParameters(['fresh' => $fresh])
            ->withHeader('x-project-secret', $projectSecret)
            ->withBody(json_encode($objects));

        if (File::exists(omnify_path('omnify.lock'))) {
            $request->attach('lock_file', omnify_path('omnify.lock'), 'omnify.lock');
        }

        return $request;
    }
}
