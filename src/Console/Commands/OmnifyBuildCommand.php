<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class OmnifyBuildCommand extends Command
{
    protected $signature = 'omnify:build {--detailed : Show detailed progress tables} {--fresh : Clean up before build (removes lock file, migrations, and OmnifyBase folders)} {--format=true : Run Laravel Pint to format extracted PHP files}';

    protected $description = 'Build schemas and post to API. Use --format=false to disable formatting';

    private array $statistics = [];

    public function handle(): void
    {
        $this->info('🚀 Starting Omnify Build Process...');

        // Fresh cleanup if requested
        if ($this->option('fresh')) {
            $this->performFreshCleanup();
        }

        // Step 1: Aggregate schemas with progress bar
        $this->newLine();
        $this->info('📂 Step 1/4: Aggregating schemas...');
        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $allSchemas = $this->aggregateAllSchemas($progressBar);
        $progressBar->finish();
        $this->newLine();
        $this->info('✅ Found ' . count($allSchemas) . ' schemas');

        // Step 2: Prepare omnify.lock
        $this->newLine();
        $this->info('🔒 Step 2/4: Reading omnify.lock...');
        $omnifyLock = null;
        $lockPath = base_path('.omnify/omnify.lock');
        if (File::exists($lockPath)) {
            $omnifyLock = File::get($lockPath);

            /**
             * CRITICAL: Base64 encoding for binary data transmission
             *
             * omnify.lock file contains encrypted binary data that MUST be transmitted safely over HTTP.
             *
             * ❌ NEVER USE: mb_convert_encoding($omnifyLock, 'UTF-8', 'UTF-8')
             *    - This function treats binary data as text and corrupts it
             *    - Invalid UTF-8 bytes are replaced with '?' characters
             *    - Result: Data corruption (e.g., 13504 bytes → 13302 bytes = 202 bytes lost)
             *    - Error: "omnify.lock is corrupted and cannot be decrypted"
             *
             * ✅ CORRECT APPROACH: base64_encode($omnifyLock)
             *    - Safely encodes binary data into ASCII text for JSON transmission
             *    - No data loss or corruption
             *    - Server side must use base64_decode() to restore original binary data
             *
             * Historical issue: Jul 13, 2025 - mb_convert_encoding caused 202-byte data loss,
             * making AES-256-CBC decryption fail due to corrupted IV and encrypted content.
             */
            $omnifyLock = base64_encode($omnifyLock);

            $this->info('✅ omnify.lock loaded');
        } else {
            $this->warn('⚠️ omnify.lock not found');
        }

        $postData = [
            'schemas' => $allSchemas,
            'omnify_lock' => $omnifyLock,
        ];

        $url = app()->environment('production')
            ? 'https://core.omnify.jp/api/schema/build'
            : 'http://omnify.test/api/schema/build';

        // Step 3: API call with progress
        $this->newLine();
        $this->info('📡 Step 3/4: Posting to API and downloading build...');
        $this->line("🔗 Endpoint: {$url}");

        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        for ($i = 0; $i <= 50; $i++) {
            $progressBar->setProgress($i);
            usleep(1000); // Small delay for visual effect
        }

        $response = Http::post($url, $postData);

        for ($i = 51; $i <= 100; $i++) {
            $progressBar->setProgress($i);
            usleep(1000);
        }
        $progressBar->finish();
        $this->newLine();

        if ($response->successful()) {
            $this->info('✅ Build downloaded successfully');

            // Check if response is actually a zip file
            $contentType = $response->header('Content-Type');
            $this->line("📄 Response content type: {$contentType}");

            // Save downloaded zip file
            $tempZipPath = storage_path('app/temp/omnify-build.zip');
            File::ensureDirectoryExists(dirname($tempZipPath));
            File::put($tempZipPath, $response->body());

            // Step 4: Extract and distribute files
            $this->newLine();
            $this->info('📦 Step 4/4: Extracting and distributing files...');
            $this->extractAndDistributeFiles($tempZipPath);

            // Cleanup temp file
            File::delete($tempZipPath);

            // Show final statistics
            $this->showFinalStatistics();
        } else {
            $this->error('❌ Failed to download build');
            $this->line("Status: {$response->status()}");
            $this->line("Body: {$response->body()}");
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

    private function extractAndDistributeFiles(string $zipFilePath): void
    {
        $tempExtractPath = storage_path('app/temp/omnify-extract');

        // Debug zip file info
        if (! File::exists($zipFilePath)) {
            throw new \Exception("Zip file does not exist: {$zipFilePath}");
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

        // Extract zip file
        $zip = new \ZipArchive;
        $result = $zip->open($zipFilePath);
        if ($result === true) {
            $zip->extractTo($tempExtractPath);
            $zip->close();
            $this->line('📦 Zip file extracted');
        } else {
            throw new \Exception("Failed to extract zip file. Error code: {$result}");
        }

        // Run pint on extracted files (enabled by default, use --format=false to disable)
        if ($this->option('format') !== false) {
            $this->runPintOnExtractedFiles($tempExtractPath);
        } else {
            $this->newLine();
            $this->line('⏩ Code formatting skipped (use --format=false to disable)');
        }

        // Read filelist.json
        $filelistPath = $tempExtractPath . '/build/filelist.json';
        if (! File::exists($filelistPath)) {
            throw new \Exception('filelist.json not found in build');
        }

        $filelist = json_decode(File::get($filelistPath), true);
        if (! $filelist) {
            throw new \Exception('Invalid filelist.json format');
        }

        $totalFiles = 0;
        foreach ($filelist as $category => $files) {
            $totalFiles += count($files);
        }

        $this->line("📋 Processing {$totalFiles} files from filelist (" . count($filelist) . ' categories)');

        // Initialize statistics
        $this->statistics = [];

        // Create progress bar for file distribution
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
                    // Check replace flag - if replace=false and destination exists, skip
                    $shouldReplace = $fileInfo['replace'] ?? true; // Default to true if not specified

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

        // Cleanup extraction directory
        File::deleteDirectory($tempExtractPath);

        $this->info('🎉 File distribution completed!');
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

            // Add skip reason if file was skipped
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

        // Add total row
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

    private function performFreshCleanup(): void
    {
        $this->newLine();
        $this->info('🧹 Fresh cleanup requested...');

        $cleanupItems = [];

        // 1. Check and remove lock file
        $lockPaths = [
            base_path('.omnify/omnify.lock'),
            base_path('omnify.lock'),
            storage_path('omnify.lock'),
        ];

        foreach ($lockPaths as $lockPath) {
            if (File::exists($lockPath)) {
                File::delete($lockPath);
                $cleanupItems[] = '✅ Removed lock file: ' . str_replace(base_path() . '/', '', $lockPath);
                break;
            }
        }

        // 2. Remove migrations/omnify folder
        $migrationsPath = database_path('migrations/omnify');
        if (File::exists($migrationsPath)) {
            File::deleteDirectory($migrationsPath);
            $cleanupItems[] = '✅ Removed migrations folder: database/migrations/omnify';
        }

        // 3. Remove OmnifyBase folders
        $omnifyBasePaths = [
            app_path('Models/OmnifyBase'),
            app_path('Http/Requests/OmnifyBase'),
            app_path('Omnify/Controllers/OmnifyBase'),
            app_path('Omnify/Services/OmnifyBase'),
            app_path('Omnify/Repositories/OmnifyBase'),
            app_path('Omnify/Requests/OmnifyBase'),
            app_path('Omnify/Resources/OmnifyBase'),
            app_path('Omnify/Policies/OmnifyBase'),
        ];

        foreach ($omnifyBasePaths as $path) {
            if (File::exists($path)) {
                File::deleteDirectory($path);
                $relativePath = str_replace(base_path() . '/', '', $path);
                $cleanupItems[] = "✅ Removed OmnifyBase folder: {$relativePath}";
            }
        }

        if (empty($cleanupItems)) {
            $this->line('   ℹ️ No cleanup needed - all items were already clean');
        } else {
            foreach ($cleanupItems as $item) {
                $this->line("   {$item}");
            }
        }

        $this->info('🧹 Fresh cleanup completed!');
    }

    private function runPintOnExtractedFiles(string $tempExtractPath): void
    {
        $this->newLine();
        $this->info('🎨 Running Laravel Pint on extracted files...');

        // Use project root for pint (will use pint.json from project root)
        $projectRootPath = base_path();

        // Check if pint exists in project root
        $pintPath = $projectRootPath . '/vendor/bin/pint';

        if (! File::exists($pintPath)) {
            $this->warn("⚠️ Pint not found at {$pintPath}. Skipping code formatting.");

            return;
        }

        // Find PHP files in the extracted directory
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

        // Create progress bar for pint process
        $progressBar = $this->output->createProgressBar(count($phpFiles));
        $progressBar->setFormat('   🎨 [%bar%] %current%/%max% files formatted (%percent:3s%%) - %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        // Run pint on each PHP file
        $successCount = 0;
        $errorCount = 0;

        foreach ($phpFiles as $index => $phpFile) {
            $fileName = basename($phpFile);
            $progressBar->setMessage("Processing: {$fileName}");

            $process = new Process(
                [$pintPath, $phpFile],
                $projectRootPath, // Working directory
                null,
                null,
                60 // 60 seconds timeout
            );

            try {
                $process->run();

                if ($process->isSuccessful()) {
                    $successCount++;
                    $progressBar->setMessage("✅ {$fileName}");
                } else {
                    $errorCount++;
                    $progressBar->setMessage("❌ {$fileName}");
                    // Still show warning but don't interrupt progress bar
                    $this->newLine();
                    $this->warn('   ⚠️ Pint failed for: ' . str_replace($tempExtractPath, '', $phpFile));
                }
            } catch (\Exception $e) {
                $errorCount++;
                $progressBar->setMessage("❌ {$fileName}");
                // Still show warning but don't interrupt progress bar
                $this->newLine();
                $this->warn('   ⚠️ Error running pint on: ' . str_replace($tempExtractPath, '', $phpFile) . ' - ' . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->newLine();

        if ($errorCount === 0) {
            $this->info("   ✅ Successfully formatted {$successCount} PHP files");
        } else {
            $this->warn("   ⚠️ Formatted {$successCount} files, {$errorCount} files had errors");
        }
    }
}
