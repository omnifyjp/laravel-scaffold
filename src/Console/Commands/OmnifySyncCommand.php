<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class OmnifySyncCommand extends Command
{
    protected $signature = 'omnify:sync {--detailed : Show detailed progress tables} {--format=true : Run Laravel Pint to format downloaded PHP files} {--skip-format : Skip code formatting entirely}';

    protected $description = 'Sync schemas from cloud server using project ID and key. Note: Use web UI to reset/fresh project state on server';

    private array $statistics = [];

    public function handle(): void
    {
        $this->info('🚀 Starting Omnify Cloud Sync Process...');

        // Step 1: Validate credentials
        $this->newLine();
        $this->info('🔐 Step 1/5: Validating credentials...');

        /** @noinspection LaravelFunctionsInspection */
        $projectId = env('OMNIFY_PROJECT_ID');
        /** @noinspection LaravelFunctionsInspection */
        $projectKey = env('OMNIFY_PROJECT_KEY');

        if (! $projectId || ! $projectKey) {
            $this->error('❌ Missing credentials! Please set OMNIFY_PROJECT_ID and OMNIFY_PROJECT_KEY in your .env file');
            $this->line('Example:');
            $this->line('OMNIFY_PROJECT_ID=your-project-id');
            $this->line('OMNIFY_PROJECT_KEY=your-project-key');

            return;
        }

        $this->info('✅ Credentials found');
        $this->line("📋 Project ID: {$projectId}");
        $this->line('🔑 Project Key: ' . substr($projectKey, 0, 8) . '...' . substr($projectKey, -4));

        // Step 2: Aggregate schemas with progress bar
        $this->newLine();
        $this->info('📂 Step 2/5: Aggregating schemas...');
        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $allSchemas = $this->aggregateAllSchemas($progressBar);
        $progressBar->finish();
        $this->newLine();
        $this->info('✅ Found ' . count($allSchemas) . ' schemas');

        // Step 3: Read omnify config
        $this->newLine();
        $this->info('⚙️  Step 3/5: Reading config...');
        $config = [];
        $configPath = base_path('.omnify/config.php');
        if (File::exists($configPath)) {
            try {
                $loadedConfig = require $configPath;

                if (! is_array($loadedConfig)) {
                    $this->warn('⚠️ .omnify/config.php must return an array, got: ' . gettype($loadedConfig));
                    $this->info('ℹ️ Using empty config instead');
                } else {
                    $config = $loadedConfig;
                    $this->info('✅ .omnify/config.php loaded successfully');
                }
            } catch (\ParseError $e) {
                $this->error('❌ .omnify/config.php has syntax error: ' . $e->getMessage());
                $this->info('ℹ️ Using empty config instead');
            } catch (Exception $e) {
                $this->error('❌ Error loading .omnify/config.php: ' . $e->getMessage());
                $this->info('ℹ️ Using empty config instead');
            }
        } else {
            $this->info('ℹ️ .omnify/config.php not found, using empty config');
        }

        // Prepare request data with authentication
        // Note: omnify.lock is managed by server, not sent from client
        $postData = [
            'project_id' => $projectId,
            'project_key' => $projectKey,
            'schemas' => $allSchemas,
            'config' => $config,
            'sync_mode' => true, // Indicate this is a sync request
        ];
        /** @noinspection LaravelFunctionsInspection */
        $url = env('OMNIFY_ENV') === 'dev'
            ? env('OMNIFY_API_URL', 'http://omnify.test/api/schema/sync')
            : 'https://core.omnify.jp/api/schema/sync';

        // Step 4: API call to sync with cloud
        $this->newLine();
        $this->info('☁️ Step 4/5: Syncing with cloud server...');
        $this->line("🔗 Endpoint: {$url}");

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        for ($i = 0; $i <= 50; $i++) {
            $progressBar->setProgress($i);
            usleep(1000);
        }

        $response = Http::timeout(60)->post($url, $postData);

        for ($i = 51; $i <= 100; $i++) {
            $progressBar->setProgress($i);
            usleep(1000);
        }
        $progressBar->finish();
        $this->newLine();

        if ($response->successful()) {
            $this->info('✅ Successfully synced with cloud server');

            // Check if the response is actually a zip file
            $contentType = $response->header('Content-Type');

            // Check if the response is JSON (error response)
            if (strpos($contentType, 'application/json') !== false) {
                $responseData = $response->json();
                if (isset($responseData['error'])) {
                    $this->error('❌ Server error: ' . $responseData['error']);
                    if (isset($responseData['message'])) {
                        $this->line('Message: ' . $responseData['message']);
                    }

                    return;
                }
            }

            $this->line("📄 Response content type: {$contentType}");

            // Save the downloaded zip file
            $tempZipPath = storage_path('app/temp/omnify-sync.zip');
            File::ensureDirectoryExists(dirname($tempZipPath));
            File::put($tempZipPath, $response->body());

            try {
                // Step 5: Extract and distribute files
                $this->newLine();
                $this->info('📦 Step 5/5: Extracting and distributing synced files...');
                $this->extractAndDistributeFiles($tempZipPath);

                // Show final statistics
                $this->showFinalStatistics();

                $this->newLine();
                $this->info('🎉 Cloud sync completed successfully!');
                $this->line('💡 Your project is now synchronized with the cloud configuration.');
            } finally {
                // Cleanup temp file - ALWAYS runs even if exception thrown
                if (File::exists($tempZipPath)) {
                    File::delete($tempZipPath);
                    $this->line('🧹 Cleaned up temporary zip file');
                }
            }
        } else {
            $this->error('❌ Failed to sync with cloud server');
            $this->line("Status: {$response->status()}");

            // Try to parse error response
            try {
                $errorBody = $response->json();
                if (isset($errorBody['error'])) {
                    $this->error('Error: ' . $errorBody['error']);
                }
                if (isset($errorBody['message'])) {
                    $this->line('Message: ' . $errorBody['message']);
                }
            } catch (Exception $e) {
                $this->line("Body: {$response->body()}");
            }

            if ($response->status() === 401) {
                $this->warn('⚠️ Authentication failed. Please check your OMNIFY_PROJECT_ID and OMNIFY_PROJECT_KEY');
            } elseif ($response->status() === 404) {
                $this->warn('⚠️ Project not found. Please verify your OMNIFY_PROJECT_ID');
            }
        }
    }


    private function extractAndDistributeFiles(string $zipFilePath): void
    {
        $tempExtractPath = storage_path('app/temp/omnify-sync-extract');

        // Debug zip file info
        if (! File::exists($zipFilePath)) {
            throw new Exception("Zip file does not exist: {$zipFilePath}");
        }

        $zipSize = File::size($zipFilePath);
        $this->line("📦 Zip file info: {$zipFilePath} (Size: {$zipSize} bytes)");

        // Check magic bytes
        $handle = fopen($zipFilePath, 'rb');
        $magicBytes = fread($handle, 4);
        fclose($handle);
        $magicHex = bin2hex($magicBytes);
        $this->line("🔍 Magic bytes: {$magicHex}");

        // Clean extraction directory
        if (File::exists($tempExtractPath)) {
            File::deleteDirectory($tempExtractPath);
        }
        File::makeDirectory($tempExtractPath, 0755, true);

        try {
            // Extract zip file
            $zip = new \ZipArchive;
            $result = $zip->open($zipFilePath);
            if ($result === true) {
                $zip->extractTo($tempExtractPath);
                $zip->close();
                $this->line('📦 Zip file extracted');
            } else {
                throw new Exception("Failed to extract zip file. Error code: {$result}");
            }

            // Run pint on extracted files (enabled by default, use --skip-format to disable)
            if ($this->option('skip-format')) {
                $this->newLine();
                $this->line('⏩ Code formatting skipped (--skip-format enabled)');
            } elseif ($this->option('format') !== false && $this->option('format') !== 'false') {
                $this->runPintOnExtractedFiles($tempExtractPath);
            } else {
                $this->newLine();
                $this->line('⏩ Code formatting skipped (--format=false)');
            }

            // Read filelist.json
            $filelistPath = $tempExtractPath . '/build/filelist.json';
            if (! File::exists($filelistPath)) {
                throw new Exception('filelist.json not found in build');
            }

            $filelist = json_decode(File::get($filelistPath), true);
            if (! $filelist) {
                throw new Exception('Invalid filelist.json format');
            }

            // Debug: Show all categories
            $this->line('🔍 DEBUG: All categories in filelist:');
            foreach ($filelist as $categoryName => $files) {
                $this->line("   - {$categoryName}: " . count($files) . ' files');
            }

            $totalFiles = 0;
            foreach ($filelist as $category => $files) {
                $totalFiles += count($files);
            }

            $this->line("📋 Processing {$totalFiles} files from filelist (" . count($filelist) . ' categories)');

            // Initialize statistics
            $this->statistics = [];

            // Create a progress bar for file distribution
            $progressBar = $this->output->createProgressBar($totalFiles);
            $progressBar->setFormat('verbose');
            $progressBar->start();

            $processedFiles = 0;

            // Distribute files according to filelist
            foreach ($filelist as $categoryName => $fileInfos) {
                $this->statistics[$categoryName] = [
                    'copied' => 0,
                    'skipped' => 0,
                    'total' => count($fileInfos),
                    'files' => [],
                ];

                foreach ($fileInfos as $fileInfo) {
                    $sourceFilePath = $tempExtractPath . '/build/' . $fileInfo['path'];
                    $destinationPath = base_path($fileInfo['destination']);
                    $status = 'copied';
                    $skipReason = null;

                    if (! File::exists($sourceFilePath)) {
                        $status = 'skipped';
                        $skipReason = 'Source file not found';
                        $this->statistics[$categoryName]['skipped']++;
                    } else {
                        // Check replace flag
                        $shouldReplace = $fileInfo['replace'] ?? true;

                        if (! $shouldReplace && File::exists($destinationPath)) {
                            $status = 'skipped';
                            $skipReason = 'File exists and replace=false';
                            $this->statistics[$categoryName]['skipped']++;
                        } else {
                            // Ensure destination directory exists
                            File::ensureDirectoryExists(dirname($destinationPath));

                            // Copy file to destination
                            File::copy($sourceFilePath, $destinationPath);
                            $this->statistics[$categoryName]['copied']++;
                        }
                    }

                    // Track file details for verbose tables
                    $this->statistics[$categoryName]['files'][] = [
                        'source' => $fileInfo['path'],
                        'destination' => $fileInfo['destination'],
                        'status' => $status,
                        'skip_reason' => $skipReason,
                    ];

                    $processedFiles++;
                    $progressBar->setProgress($processedFiles);
                }

                // Show category table if detailed
                if ($this->option('detailed')) {
                    $this->showCategoryTable($categoryName);
                }
            }

            $progressBar->finish();
            $this->newLine();

            $this->info('🎉 File distribution completed!');
        } finally {
            // Cleanup extraction directory - ALWAYS runs even if exception thrown
            if (File::exists($tempExtractPath)) {
                File::deleteDirectory($tempExtractPath);
            }
        }
    }

    private function showCategoryTable(string $categoryName): void
    {
        $categoryData = $this->statistics[$categoryName];

        $this->newLine();
        $this->line("📊 {$categoryName} Details:");

        $headers = ['Destination', 'Status'];
        $rows = [];

        foreach ($categoryData['files'] as $file) {
            $statusIcon = $file['status'] === 'copied' ? '✅' : '⚠️';
            $statusText = ucfirst($file['status']);

            if ($file['status'] === 'skipped' && ! empty($file['skip_reason'])) {
                $statusText .= ' (' . $file['skip_reason'] . ')';
            }

            $rows[] = [
                $file['destination'],
                $statusIcon . ' ' . $statusText,
            ];
        }

        $this->table($headers, $rows);

        $copied = $categoryData['copied'];
        $skipped = $categoryData['skipped'];
        $total = $categoryData['total'];

        $this->line("📈 Category Summary: {$copied} copied, {$skipped} skipped, {$total} total");
    }

    private function showFinalStatistics(): void
    {
        $this->newLine();
        $this->info('📊 Final Statistics:');

        $headers = ['Category', 'Copied', 'Skipped', 'Total', 'Success Rate'];
        $rows = [];

        $totalCopied = 0;
        $totalSkipped = 0;
        $totalFiles = 0;

        foreach ($this->statistics as $categoryName => $stats) {
            $copied = $stats['copied'];
            $skipped = $stats['skipped'];
            $total = $stats['total'];
            $successRate = $total > 0 ? round(($copied / $total) * 100, 1) . '%' : '0%';

            $rows[] = [
                $categoryName,
                $copied,
                $skipped,
                $total,
                $successRate,
            ];

            $totalCopied += $copied;
            $totalSkipped += $skipped;
            $totalFiles += $total;
        }

        // Add the total row
        $overallSuccessRate = $totalFiles > 0 ? round(($totalCopied / $totalFiles) * 100, 1) . '%' : '0%';
        $rows[] = [
            '<fg=yellow>TOTAL</>',
            "<fg=green>{$totalCopied}</>",
            "<fg=red>{$totalSkipped}</>",
            "<fg=blue>{$totalFiles}</>",
            "<fg=cyan>{$overallSuccessRate}</>",
        ];

        $this->table($headers, $rows);

        if ($totalSkipped > 0) {
            // Analyze skip reasons
            $skipReasons = [];
            foreach ($this->statistics as $categoryName => $stats) {
                foreach ($stats['files'] as $file) {
                    if ($file['status'] === 'skipped' && ! empty($file['skip_reason'])) {
                        $reason = $file['skip_reason'];
                        if (! isset($skipReasons[$reason])) {
                            $skipReasons[$reason] = 0;
                        }
                        $skipReasons[$reason]++;
                    }
                }
            }

            $this->warn("⚠️ {$totalSkipped} files were skipped:");
            foreach ($skipReasons as $reason => $count) {
                $this->line("   • {$count} files: {$reason}");
            }
        } else {
            $this->info("🎯 All {$totalCopied} files were successfully copied!");
        }
    }

    private function runPintOnExtractedFiles(string $tempExtractPath): void
    {
        $this->newLine();
        $this->info('🎨 Running Laravel Pint on extracted files...');

        $projectRootPath = base_path();
        $pintPath = $projectRootPath . '/vendor/bin/pint';

        if (! File::exists($pintPath)) {
            $this->warn("⚠️ Pint not found at {$pintPath}. Skipping code formatting.");

            return;
        }

        // Count PHP files for a progress indication
        $phpFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempExtractPath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $phpFiles[] = $file->getPathname();
            }
        }

        if (empty($phpFiles)) {
            $this->line('   ℹ️ No PHP files found to format');

            return;
        }

        $this->line('   📝 Found ' . count($phpFiles) . ' PHP files to format');

        // Create a progress bar
        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('   🎨 [%bar%] %percent:3s%% - %message%');
        $progressBar->setMessage('Processing directory with Laravel Pint...');
        $progressBar->start();

        // Run pint on the entire directory
        $process = new Process(
            [$pintPath, $tempExtractPath, '--parallel'],
            $projectRootPath,
            null,
            null,
            120
        );

        try {
            $process->run(function ($type, $buffer) use ($progressBar) {
                static $progress = 0;
                $progress = min(90, $progress + 10);
                $progressBar->setProgress($progress);
            });

            $progressBar->setProgress(100);
            $progressBar->setMessage('Complete!');
            $progressBar->finish();
            $this->newLine();

            if ($process->isSuccessful()) {
                $this->info('   ✅ Successfully formatted ' . count($phpFiles) . ' PHP files');

                $output = trim($process->getOutput());
                if (! empty($output)) {
                    $this->line('   📋 Pint output:');
                    $this->line('   ' . str_replace("\n", "\n   ", $output));
                }
            } else {
                $this->warn('   ⚠️ Pint completed with warnings');
                $this->line('   Error output: ' . $process->getErrorOutput());
            }
        } catch (Exception $e) {
            $progressBar->setMessage('Error occurred!');
            $progressBar->finish();
            $this->newLine();
            $this->warn('   ⚠️ Error running pint: ' . $e->getMessage());
        }
    }

    private function aggregateAllSchemas($progressBar = null): array
    {
        $result = [];
        $processedFiles = 0;

        $schemaPaths = [
            base_path('database/schemas'),
        ];

        // Count total files first
        $totalFiles = 0;
        foreach ($schemaPaths as $schemaPath) {
            if (! File::exists($schemaPath) || ! File::isDirectory($schemaPath)) {
                continue;
            }
            $groupDirectories = File::directories($schemaPath);
            foreach ($groupDirectories as $groupDir) {
                $yamlFiles = File::glob($groupDir . '/*.yaml');
                $totalFiles += count($yamlFiles);
            }
        }

        foreach ($schemaPaths as $schemaPath) {
            if (! File::exists($schemaPath) || ! File::isDirectory($schemaPath)) {
                continue;
            }

            $groupDirectories = File::directories($schemaPath);

            foreach ($groupDirectories as $groupDir) {
                $groupName = basename($groupDir);
                $yamlFiles = File::glob($groupDir . '/*.yaml');

                foreach ($yamlFiles as $yamlFile) {
                    $fileName = basename($yamlFile, '.yaml');
                    $relativePath = str_replace(base_path() . '/', '', $yamlFile);

                    $yamlContent = File::get($yamlFile);
                    $yamlContent = mb_convert_encoding($yamlContent, 'UTF-8', 'UTF-8');
                    $parsedContent = Yaml::parse($yamlContent);

                    if (! isset($parsedContent['objectName'])) {
                        $parsedContent['objectName'] = $fileName;
                    }

                    if (! isset($parsedContent['groupName'])) {
                        $parsedContent['groupName'] = $groupName;
                    }

                    $key = $parsedContent['objectName'];
                    if (isset($result[$key])) {
                        if (strpos($relativePath, 'database/schemas') !== false) {
                            $result[$key] = $parsedContent;
                        }
                    } else {
                        $result[$key] = $parsedContent;
                    }

                    $processedFiles++;
                    if ($progressBar && $totalFiles > 0) {
                        $progressBar->setProgress(($processedFiles / $totalFiles) * 100);
                    }
                }
            }
        }

        ksort($result);

        return $result;
    }
}
