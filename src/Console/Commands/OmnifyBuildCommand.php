<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class OmnifyBuildCommand extends Command
{
    protected $signature = 'omnify:build {--detailed : Show detailed progress tables}';

    protected $description = 'Build schemas and post to API';

    private array $statistics = [];

    public function handle(): void
    {
        $this->info('🚀 Starting Omnify Build Process...');

        // Step 1: Aggregate schemas with progress bar
        $this->newLine();
        $this->info('📂 Step 1/4: Aggregating schemas...');
        $progressBar = $this->output->createProgressBar(100);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $allSchemas = $this->aggregateAllSchemas($progressBar);
        $progressBar->finish();
        $this->newLine();
        $this->info("✅ Found " . count($allSchemas) . " schemas");

        // Step 2: Prepare omnify.lock
        $this->newLine();
        $this->info('🔒 Step 2/4: Reading omnify.lock...');
        $omnifyLock = null;
        $lockPath = base_path('.omnify/omnify.lock');
        if (File::exists($lockPath)) {
            $omnifyLock = File::get($lockPath);
            $omnifyLock = mb_convert_encoding($omnifyLock, 'UTF-8', 'UTF-8');
            $this->info('✅ omnify.lock loaded');
        } else {
            $this->warn('⚠️ omnify.lock not found');
        }

        $postData = [
            'schemas' => $allSchemas,
            'omnify_lock' => $omnifyLock
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
            if (!File::exists($schemaPath) || !File::isDirectory($schemaPath)) {
                continue;
            }
            $groupDirectories = File::directories($schemaPath);
            foreach ($groupDirectories as $groupDir) {
                $yamlFiles = File::glob($groupDir . '/*.yaml');
                $totalFiles += count($yamlFiles);
            }
        }

        foreach ($schemaPaths as $schemaPath) {
            if (!File::exists($schemaPath) || !File::isDirectory($schemaPath)) {
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

                    if (!isset($parsedContent['objectName'])) {
                        $parsedContent['objectName'] = $fileName;
                    }

                    if (!isset($parsedContent['groupName'])) {
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
        if (!File::exists($zipFilePath)) {
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
        if ($result === TRUE) {
            $zip->extractTo($tempExtractPath);
            $zip->close();
            $this->line('📦 Zip file extracted');
        } else {
            throw new \Exception("Failed to extract zip file. Error code: {$result}");
        }

        // Read filelist.json
        $filelistPath = $tempExtractPath . '/build/filelist.json';
        if (!File::exists($filelistPath)) {
            throw new \Exception('filelist.json not found in build');
        }

        $filelist = json_decode(File::get($filelistPath), true);
        if (!$filelist) {
            throw new \Exception('Invalid filelist.json format');
        }

        $totalFiles = 0;
        foreach ($filelist as $category => $files) {
            $totalFiles += count($files);
        }

        $this->line("📋 Processing {$totalFiles} files from filelist (" . count($filelist) . " categories)");

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
                'files' => []
            ];



            foreach ($fileInfos as $fileInfo) {
                $sourceFilePath = $tempExtractPath . '/build/' . $fileInfo['path'];
                $destinationPath = base_path($fileInfo['destination']);
                $status = 'copied';
                $skipReason = null;

                if (!File::exists($sourceFilePath)) {
                    $status = 'skipped';
                    $skipReason = 'Source file not found';
                    $this->statistics[$categoryName]['skipped']++;
                } else {
                    // Check replace flag - if replace=false and destination exists, skip
                    $shouldReplace = $fileInfo['replace'] ?? true; // Default to true if not specified

                    if (!$shouldReplace && File::exists($destinationPath)) {
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
                    'skip_reason' => $skipReason
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
            if ($file['status'] === 'skipped' && !empty($file['skip_reason'])) {
                $statusText .= ' (' . $file['skip_reason'] . ')';
            }

            $rows[] = [
                $file['destination'],
                $statusIcon . ' ' . $statusText
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
                $successRate
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
            "<fg=cyan>{$overallSuccessRate}</>"
        ];

        $this->table($headers, $rows);

        if ($totalSkipped > 0) {
            // Analyze skip reasons
            $skipReasons = [];
            foreach ($this->statistics as $categoryName => $stats) {
                foreach ($stats['files'] as $file) {
                    if ($file['status'] === 'skipped' && !empty($file['skip_reason'])) {
                        $reason = $file['skip_reason'];
                        if (!isset($skipReasons[$reason])) {
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
}
