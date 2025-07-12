<?php

namespace OmnifyJP\LaravelScaffold\Services;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class OmnifyGeneratorService
{
    protected Command $command;

    protected string $baseDir;

    protected string $outputDir;

    protected string $tempZipFile;

    protected array $spinnerFrames = [
        '⠋',
        '⠙',
        '⠹',
        '⠸',
        '⠼',
        '⠴',
        '⠦',
        '⠧',
        '⠇',
        '⠏',
    ];

    protected array $networkFrames = [
        '⢎⡰ ⢎⡱ ⢎⡱',
        '⢎⡱ ⢎⡱ ⢎⡰',
        '⢎⡱ ⢎⡰ ⢎⡰',
        '⢎⡡ ⢎⡱ ⢎⡱',
        '⢎⡡ ⢎⡡ ⢎⡱',
        '⡎⢱ ⢎⡡ ⢎⡡',
    ];

    /**
     * Migration statistics
     */
    protected array $migrationStats = [
        'deleted' => [],
        'installed' => [],
        'skipped' => [],
        'exists' => [],
    ];

    /**
     * Laravel files statistics
     */
    protected array $laravelStats = [
        'models' => ['installed' => [], 'exists' => []],
        'factories' => ['installed' => [], 'exists' => []],
        'bootstrap' => ['installed' => [], 'exists' => []],
    ];

    /**
     * OmnifyGeneratorService constructor.
     */
    public function __construct(Command $command)
    {
        $this->command = $command;
        $this->baseDir = support_omnify_path('');
        $this->outputDir = support_omnify_path('.temp');
        $this->tempZipFile = support_omnify_path('.temp/temp.zip');

        // Ensure output directory exists
        File::makeDirectory($this->outputDir, 0755, true, true);
    }

    /**
     * Generate objects from schema files
     */
    public function generateObjects(): array
    {
        $objects = [];
        foreach ([database_path('schemas')] as $_directory) {
            if (! File::exists($_directory)) {
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
     * @param  mixed  $response
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
            $this->command->info('✓ Extraction completed successfully');
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
     * @param  mixed  $response
     */
    private function displayFormattedError($response): void
    {
        $body = json_decode($response->body(), true);

        $this->command->newLine();

        if (is_array($body)) {
            $errors = [];

            // Collect all error messages
            if (isset($body['message']) && ! empty($body['message'])) {
                $errors[] = $body['message'];
            }

            if (isset($body['error']) && ! empty($body['error'])) {
                $errors[] = $body['error'];
            }

            // Handle validation errors
            if (isset($body['errors']) && is_array($body['errors'])) {
                foreach ($body['errors'] as $field => $fieldErrors) {
                    if (is_array($fieldErrors)) {
                        foreach ($fieldErrors as $error) {
                            $errors[] = "{$field}: {$error}";
                        }
                    } else {
                        $errors[] = "{$field}: {$fieldErrors}";
                    }
                }
            }

            // Handle data errors
            if (isset($body['data']) && is_array($body['data'])) {
                foreach ($body['data'] as $key => $value) {
                    if (is_string($value) && ! empty($value)) {
                        $errors[] = "{$key}: {$value}";
                    }
                }
            }

            // Display errors
            if (! empty($errors)) {
                $totalErrors = count($errors);
                $this->command->error("❌ PROBLEM: Found {$totalErrors} error(s)");
                $this->command->newLine();

                foreach ($errors as $index => $error) {
                    $errorNumber = $index + 1;
                    $separator = str_repeat('=', 15) . " #{$errorNumber} " . str_repeat('=', 15);

                    $this->command->line("<fg=yellow>{$separator}</>");
                    $this->command->line($error);
                    $this->command->newLine();
                }
            } else {
                $statusCode = $response->status();
                $this->command->error("❌ PROBLEM: HTTP {$statusCode} - Could not identify specific error");
            }
        } else {
            // If response is not JSON, display raw response
            $rawBody = $response->body();
            $this->command->error('❌ PROBLEM: Found 1 error');
            $this->command->newLine();
            $this->command->line('<fg=yellow>' . str_repeat('=', 15) . ' error #1 ' . str_repeat('=', 15) . '</>');
            $this->command->line(! empty($rawBody) ? $rawBody : 'Unknown error occurred');
            $this->command->newLine();
        }

        // Show raw JSON only in verbose mode
        if ($this->command->getOutput()->isVerbose() && is_array($body)) {
            $this->command->line('<fg=gray>Raw Response:</>');
            $this->command->line(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Clean up directories for fresh installation
     */
    public function cleanDirectoriesForFresh(): void
    {
        $this->command->info('Preparing for fresh installation');
        $this->showSpinner('  Cleaning existing files', 2);

        // .fammディレクトリから不要なファイルを削除
        File::deleteDirectory(support_omnify_path('app/Models/Base'));
        File::deleteDirectory(support_omnify_path('ts/Models/Base'));

        // .famm/database ディレクトリを完全に削除（古いファイルの残骸を防ぐため）
        $oldDatabasePath = support_omnify_path('database');
        if (File::exists($oldDatabasePath)) {
            File::deleteDirectory($oldDatabasePath);
        }

        // Laravelのdatabaseディレクトリから古いomnify関連ファイルを削除
        $this->migrationStats['deleted'] = $this->cleanOldOmnifyMigrations();
        $this->cleanOldOmnifySeeders();

        $this->command->info('✓ Directory cleanup completed');
    }

    /**
     * Move files based on file list
     *
     * @throws FileNotFoundException
     */
    public function moveFilesBasedOnFileList(string $fileListPath): bool
    {
        $fileListContent = File::get($fileListPath);
        $fileList = json_decode($fileListContent, true);

        if (! is_array($fileList)) {
            $this->command->error('Invalid format of filelist.json.');

            return false;
        }

        // Convert legacy format to new format
        $fileList = $this->convertLegacyFileListFormat($fileList);

        $totalFiles = count($fileList);
        $this->command->info('Preparing for installation');
        $this->showSpinner('  Analyzing file structure', 2);

        $this->command->info("Installing {$totalFiles} files");

        $progressBar = $this->command->getOutput()->createProgressBar($totalFiles);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $stats = [
            'famm' => ['installed' => [], 'skipped' => [], 'exists' => []],
            'laravel' => ['installed' => [], 'skipped' => [], 'exists' => []],
        ];

        foreach ($fileList as $fileInfo) {
            // Validate required fields - NO AUTO-CALCULATION!
            $sourcePath = $fileInfo['source_path'] ?? $fileInfo['path'] ?? '';
            $destinationPath = $fileInfo['destination_path'] ?? null;

            if (empty($sourcePath)) {
                $this->command->error('Invalid filelist entry: missing source_path');
                $progressBar->advance();

                continue;
            }

            if (! isset($fileInfo['replace'])) {
                $this->command->error("Invalid filelist entry: missing replace flag for {$sourcePath}");
                $progressBar->advance();

                continue;
            }

            // CRITICAL: Destination path is REQUIRED after conversion
            if (empty($destinationPath)) {
                $this->command->error("❌ SECURITY ERROR: Missing destination_path for {$sourcePath}");
                $this->command->error('   All file operations must be explicitly defined in filelist.json');
                $this->command->error('   This file was not properly converted from legacy format');

                return false;
            }

            $fullSourcePath = $this->outputDir . '/' . $sourcePath;

            if (! File::exists($fullSourcePath)) {
                $progressBar->advance();

                continue;
            }

            // Determine if Laravel file từ source_path (chứa 'laravel/')
            $isLaravelFile = str_contains($sourcePath, 'laravel/');

            $this->processFile($fileInfo, $fullSourcePath, $destinationPath, $isLaravelFile, $stats);

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->command->newLine();

        // Move filelist.json to .famm directory after processing
        $this->moveFileListToFamm($fileListPath, $fileList);

        $this->showInstallationSummary($stats);

        return true;
    }

    /**
     * Convert legacy filelist format to new secure format
     * Legacy: {"path": "...", "replace": true}
     * Insecure: {"source_path": "...", "destination_path": ".famm/...", "replace": true}
     * New: {"source_path": "...", "destination_path": "...", "replace": true}
     */
    private function convertLegacyFileListFormat(array $fileList): array
    {
        $convertedList = [];
        $hasLegacyFormat = false;

        foreach ($fileList as $fileInfo) {
            // Check if this is legacy format (has 'path' but no 'source_path')
            if (isset($fileInfo['path']) && ! isset($fileInfo['source_path'])) {
                $hasLegacyFormat = true;
                $sourcePath = $fileInfo['path'];

                // SECURITY: Calculate destination path based on source path pattern
                $destinationPath = $this->calculateDestinationPathFromLegacyPath($sourcePath);

                $convertedList[] = [
                    'source_path' => $sourcePath,
                    'destination_path' => $destinationPath,
                    'replace' => $fileInfo['replace'] ?? false,
                ];
            } else {
                // Check if destination_path has .famm/ prefix (insecure format from external API)
                $destinationPath = $fileInfo['destination_path'];
                if (str_starts_with($destinationPath, '.famm/')) {
                    $hasLegacyFormat = true; // Mark as legacy to show warning
                    // Remove .famm/ prefix from destination_path
                    $destinationPath = substr($destinationPath, 6); // Remove '.famm/'
                }

                $convertedList[] = [
                    'source_path' => $fileInfo['source_path'],
                    'destination_path' => $destinationPath,
                    'replace' => $fileInfo['replace'] ?? false,
                ];
            }
        }

        if ($hasLegacyFormat) {
            $this->command->warn('⚠️  Converted legacy filelist format to secure format');
            $this->command->info('   All file operations are now explicitly defined');
            $this->command->info('   Removed .famm/ prefix from destination paths for security');
        }

        return $convertedList;
    }

    /**
     * Calculate destination path from legacy path format
     * This is ONLY for converting legacy format - should be avoided in new code
     * Returns relative path from Laravel base_path, not absolute path
     */
    private function calculateDestinationPathFromLegacyPath(string $sourcePath): string
    {
        // Laravel project files (contain 'laravel/')
        if (str_contains($sourcePath, 'laravel/')) {
            $relativePath = str_replace('laravel/', '', $sourcePath);

            return $relativePath; // Return relative path from Laravel base
        }

        // .famm directory files - ALSO return without .famm prefix
        // The .famm prefix will be added during file processing
        return $sourcePath; // Return source path as-is for .famm files
    }

    /**
     * Move filelist.json to .famm directory and save in new secure format
     */
    private function moveFileListToFamm(string $fileListPath, array $convertedFileList): void
    {
        $fammFileListPath = support_omnify_path('filelist.json');

        try {
            // Ensure .famm directory exists
            File::makeDirectory(support_omnify_path(''), 0755, true, true);

            // Save converted filelist in new secure format
            $jsonContent = json_encode($convertedFileList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            File::put($fammFileListPath, $jsonContent);

            $this->command->info('✓ Moved filelist.json to .famm directory');
            $this->command->info('✓ Saved filelist.json in secure format with explicit destination paths');

            if ($this->command->getOutput()->isVerbose()) {
                $this->command->info("   From: {$fileListPath}");
                $this->command->info("   To: {$fammFileListPath}");
                $this->command->info('   Format: Secure (source_path + destination_path + replace)');
            }
        } catch (\Exception $e) {
            $this->command->warn("⚠️  Could not move filelist.json to .famm directory: {$e->getMessage()}");
        }
    }

    /**
     * ファイルを処理 (Laravel project files & .famm directory files)
     * SECURITY: destination_path is REQUIRED - no auto-calculation
     */
    private function processFile(array $fileInfo, string $sourcePath, string $destinationPath, bool $isLaravelFile, array &$stats): void
    {
        // Add .famm/ prefix for .famm files, keep as-is for Laravel files
        $actualDestinationPath = $isLaravelFile
            ? $destinationPath  // Laravel files: use destination_path as-is
            : '.famm/' . $destinationPath; // .famm files: add .famm/ prefix

        // Convert relative destination path to absolute path for file operations
        $targetPath = str_starts_with($actualDestinationPath, '/')
            ? $actualDestinationPath  // Already absolute path
            : base_path($actualDestinationPath); // Convert relative to absolute

        // ディレクトリを作成
        $targetDirectory = dirname($targetPath);
        if (! File::exists($targetDirectory)) {
            File::makeDirectory($targetDirectory, 0755, true, true);
        }

        $fileName = basename($targetPath);
        $shouldReplace = $fileInfo['replace'] ?? false;

        $statsKey = $isLaravelFile ? 'laravel' : 'famm';

        // Display path should show the actual destination (with .famm/ for .famm files)
        $displayPath = $actualDestinationPath;

        if ($shouldReplace || ! File::exists($targetPath)) {
            File::copy($sourcePath, $targetPath, true);
            $stats[$statsKey]['installed'][] = [
                'file_name' => $fileName,
                'full_path' => $displayPath,
                'source_path' => $fileInfo['source_path'] ?? $fileInfo['path'],
                'destination_path' => $destinationPath, // Store original destination_path (without .famm/)
            ];

            if ($this->command->getOutput()->isVerbose()) {
                $this->command->info("✓ Installed: {$displayPath}");
            }
        } else {
            $stats[$statsKey]['skipped'][] = [
                'file_name' => $fileName,
                'full_path' => $displayPath,
                'source_path' => $fileInfo['source_path'] ?? $fileInfo['path'],
                'destination_path' => $destinationPath, // Store original destination_path (without .famm/)
            ];

            if ($this->command->getOutput()->isVerbose()) {
                $this->command->warn("⚠️  Skipped (exists): {$displayPath}");
            }
        }
    }

    /**
     * インストール結果の概要を表示
     */
    private function showInstallationSummary(array $stats): void
    {
        $this->command->info('📦 Installation Summary');
        $this->command->newLine();

        // Laravel files summary
        $laravelInstalled = count($stats['laravel']['installed']);
        $laravelSkipped = count($stats['laravel']['skipped']);

        $this->command->info('🚀 Laravel Project Files:');
        $this->command->info("   ✓ Installed: {$laravelInstalled}");
        if ($laravelSkipped > 0) {
            $this->command->info("   ⚠️  Skipped: {$laravelSkipped}");
        }

        // .famm files summary
        $fammInstalled = count($stats['famm']['installed']);
        $fammSkipped = count($stats['famm']['skipped']);

        $this->command->info('📁 .famm Directory Files:');
        $this->command->info("   ✓ Installed: {$fammInstalled}");
        if ($fammSkipped > 0) {
            $this->command->info("   ⚠️  Skipped: {$fammSkipped}");
        }

        $this->command->newLine();

        // Show detailed tables
        $this->showDetailedFileTables($stats);
    }

    /**
     * 詳細なファイルテーブルを表示
     */
    private function showDetailedFileTables(array $stats): void
    {
        // Laravel Files Detail Table
        if (! empty($stats['laravel']['installed']) || ! empty($stats['laravel']['skipped'])) {
            $this->command->info('🚀 Laravel Project Files Detail:');
            $this->showFileTable($stats['laravel'], 'Laravel');
            $this->command->newLine();
        }

        // .famm Files Detail Table - phân chia theo loại file
        if (! empty($stats['famm']['installed']) || ! empty($stats['famm']['skipped'])) {
            $this->command->info('📁 .famm Directory Files Detail:');

            // Phân loại files theo extension và thư mục
            $categorizedFiles = $this->categorizeFiles($stats['famm']);

            foreach ($categorizedFiles as $category => $files) {
                if (! empty($files['installed']) || ! empty($files['skipped'])) {
                    $this->command->info("  📂 {$category}:");
                    $this->showFileTable($files, $category, true);
                    $this->command->newLine();
                }
            }
        }
    }

    /**
     * Phân loại files theo loại để hiển thị chi tiết hơn
     */
    private function categorizeFiles(array $fammStats): array
    {
        $categories = [
            'TypeScript Models' => ['installed' => [], 'skipped' => []],
            'TypeScript Enums' => ['installed' => [], 'skipped' => []],
            'PHP Repositories' => ['installed' => [], 'skipped' => []],
            'PHP Providers' => ['installed' => [], 'skipped' => []],
            'Other Files' => ['installed' => [], 'skipped' => []],
        ];

        // Phân loại installed files
        foreach ($fammStats['installed'] as $fileInfo) {
            $filePath = $fileInfo['full_path'];
            $fileName = $fileInfo['file_name'];

            if (str_contains($filePath, '/ts/Models/')) {
                $categories['TypeScript Models']['installed'][] = $fileInfo;
            } elseif (str_contains($filePath, '/ts/Enums/')) {
                $categories['TypeScript Enums']['installed'][] = $fileInfo;
            } elseif (str_contains($filePath, '/app/Repositories/')) {
                $categories['PHP Repositories']['installed'][] = $fileInfo;
            } elseif (str_contains($filePath, '/app/Providers/')) {
                $categories['PHP Providers']['installed'][] = $fileInfo;
            } else {
                $categories['Other Files']['installed'][] = $fileInfo;
            }
        }

        // Phân loại skipped files
        foreach ($fammStats['skipped'] as $fileInfo) {
            $filePath = $fileInfo['full_path'];
            $fileName = $fileInfo['file_name'];

            if (str_contains($filePath, '/ts/Models/')) {
                $categories['TypeScript Models']['skipped'][] = $fileInfo;
            } elseif (str_contains($filePath, '/ts/Enums/')) {
                $categories['TypeScript Enums']['skipped'][] = $fileInfo;
            } elseif (str_contains($filePath, '/app/Repositories/')) {
                $categories['PHP Repositories']['skipped'][] = $fileInfo;
            } elseif (str_contains($filePath, '/app/Providers/')) {
                $categories['PHP Providers']['skipped'][] = $fileInfo;
            } else {
                $categories['Other Files']['skipped'][] = $fileInfo;
            }
        }

        return $categories;
    }

    /**
     * ファイルテーブルを表示
     */
    private function showFileTable(array $fileStats, string $category, bool $isCategorized = false): void
    {
        $tableData = [];

        // Add installed files
        foreach ($fileStats['installed'] as $fileInfo) {
            $tableData[] = [
                'File Name' => $fileInfo['file_name'],
                'Status' => '✅ Installed',
                'Full Path' => $fileInfo['full_path'],
            ];
        }

        // Add skipped files
        foreach ($fileStats['skipped'] as $fileInfo) {
            $tableData[] = [
                'File Name' => $fileInfo['file_name'],
                'Status' => '⚠️  Skipped',
                'Full Path' => $fileInfo['full_path'],
            ];
        }

        // Add existing files that were not processed
        if (isset($fileStats['exists'])) {
            foreach ($fileStats['exists'] as $fileInfo) {
                $fileName = is_array($fileInfo) ? $fileInfo['file_name'] : $fileInfo;
                $fullPath = is_array($fileInfo) ? $fileInfo['full_path'] : $fileName;

                $tableData[] = [
                    'File Name' => $fileName,
                    'Status' => '📄 Exists',
                    'Full Path' => $fullPath,
                ];
            }
        }

        if (empty($tableData)) {
            if ($isCategorized) {
                $this->command->info('    No files in this category');
            } else {
                $this->command->info('  No files to display');
            }

            return;
        }

        // Sort by file name
        usort($tableData, function ($a, $b) {
            return strcmp($a['File Name'], $b['File Name']);
        });

        // Check if we should use list format (better for long paths)
        $useListFormat = $this->shouldUseListFormat($tableData);

        if ($useListFormat) {
            $this->displayFileList($tableData, $isCategorized);
        } else {
            // Display compact table với indentation nếu là subcategory
            if ($isCategorized) {
                $this->command->line('    ' . str_repeat('-', 50));
                foreach ($tableData as $row) {
                    $this->command->line(sprintf(
                        '    │ %-25s │ %-12s │ %s',
                        $row['File Name'],
                        $row['Status'],
                        $row['Full Path']
                    ));
                }
                $this->command->line('    ' . str_repeat('-', 50));
            } else {
                // Display normal table
                $this->command->table(
                    ['File Name', 'Status', 'Full Path'],
                    $tableData
                );
            }
        }

        // Show statistics với indentation nếu là subcategory
        $installedCount = count($fileStats['installed']);
        $skippedCount = count($fileStats['skipped']);
        $existsCount = isset($fileStats['exists']) ? count($fileStats['exists']) : 0;

        $summaryParts = [];
        if ($installedCount > 0) {
            $summaryParts[] = "{$installedCount} installed";
        }
        if ($skippedCount > 0) {
            $summaryParts[] = "{$skippedCount} skipped";
        }
        if ($existsCount > 0) {
            $summaryParts[] = "{$existsCount} exists";
        }

        if (! empty($summaryParts)) {
            $prefix = $isCategorized ? '    📊 ' : '📊 ';
            $this->command->info("{$prefix}{$category} Summary: " . implode(', ', $summaryParts));
        }
    }

    /**
     * Check if we should use list format instead of table
     */
    private function shouldUseListFormat(array $tableData): bool
    {
        // Use list format if any path is longer than 50 characters
        foreach ($tableData as $row) {
            if (strlen($row['Full Path']) > 50) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display files as a list (better for long paths)
     */
    private function displayFileList(array $tableData, bool $isCategorized = false): void
    {
        $prefix = $isCategorized ? '    ' : '  ';

        foreach ($tableData as $row) {
            $this->command->line(sprintf(
                '%s%s <fg=cyan>%s</> → <fg=yellow>%s</>',
                $prefix,
                $row['Status'],
                $row['File Name'],
                $row['Full Path']
            ));
        }
    }

    /**
     * Clean old omnify migration files when using fresh mode
     */
    public function cleanOldOmnifyMigrations(): array
    {
        $omnifyMigrationsPath = database_path('migrations/omnify');
        $deletedFiles = [];

        if (! File::exists($omnifyMigrationsPath)) {
            return $deletedFiles;
        }

        $this->command->info('Cleaning old omnify migration files');

        // 🔥 LOGIC XÓA NGAY LẬP TỨC - Delete entire directory and recreate
        // Thay vì tìm từng file, xóa luôn toàn bộ thư mục
        try {
            // Lấy danh sách tất cả files trước khi xóa (cho logging)
            $allFiles = File::allFiles($omnifyMigrationsPath);
            foreach ($allFiles as $file) {
                $deletedFiles[] = $file->getFilename();
            }

            // XÓA TOÀN BỘ THƯMỤC omnify migrations (bao gồm nested folders)
            File::deleteDirectory($omnifyMigrationsPath);

            // Tạo lại thư mục trống
            File::makeDirectory($omnifyMigrationsPath, 0755, true, true);

            $this->command->info('✓ Omnify migrations directory completely cleaned');
            $this->command->info('  - ' . count($deletedFiles) . ' files deleted (including nested folders)');

            if ($this->command->getOutput()->isVerbose()) {
                foreach ($deletedFiles as $fileName) {
                    $this->command->info("  Deleted: {$fileName}");
                }
            }
        } catch (\Exception $e) {
            $this->command->error('Failed to clean omnify migrations directory: ' . $e->getMessage());

            // Fallback to old logic if directory deletion fails
            $omnifyMigrationFiles = File::glob($omnifyMigrationsPath . '/*.php');
            foreach ($omnifyMigrationFiles as $filePath) {
                $fileName = basename($filePath);
                if (File::delete($filePath)) {
                    $deletedFiles[] = $fileName;
                    if ($this->command->getOutput()->isVerbose()) {
                        $this->command->info("  Deleted: {$fileName}");
                    }
                }
            }
        }

        return $deletedFiles;
    }

    /**
     * Clean old omnify seeder files when using fresh mode
     * Only delete files that are confirmed to be generated by omnify
     */
    public function cleanOldOmnifySeeders(): void
    {
        $seedersPath = database_path('seeders');

        if (! File::exists($seedersPath)) {
            return;
        }

        $this->command->info('Cleaning old omnify seeder files');

        // omnify生成のシーダーファイルを探す (*Seeder.php、DatabaseSeeder.php以外)
        $seederFiles = File::glob($seedersPath . '/*Seeder.php');
        // DatabaseSeeder.phpを除外
        $seederFiles = array_filter($seederFiles, function ($file) {
            return basename($file) !== 'DatabaseSeeder.php';
        });

        if (empty($seederFiles)) {
            $this->command->info('  No old omnify seeder files found');

            return;
        }

        $deletedCount = 0;
        $skippedCount = 0;

        foreach ($seederFiles as $filePath) {
            $fileName = basename($filePath);

            // 安全性チェック: ファイルがOmnifyによって生成されたかを確認
            if ($this->isOmnifyGeneratedSeeder($filePath)) {
                if (File::delete($filePath)) {
                    $deletedCount++;

                    if ($this->command->getOutput()->isVerbose()) {
                        $this->command->info("  Deleted: {$fileName}");
                    }
                }
            } else {
                $skippedCount++;
                if ($this->command->getOutput()->isVerbose()) {
                    $this->command->warn("  Skipped (not omnify generated): {$fileName}");
                }
            }
        }

        $this->command->info('✓ Old omnify seeder files cleaned');
        $this->command->info("  - {$deletedCount} files deleted, {$skippedCount} files preserved");
    }

    /**
     * Check if a seeder file was generated by omnify
     * Only delete files that have omnify markers to prevent accidental deletion
     */
    private function isOmnifyGeneratedSeeder(string $filePath): bool
    {
        if (! File::exists($filePath)) {
            return false;
        }

        try {
            $content = File::get($filePath);

            // omnify生成ファイルの特徴を検索
            $omnifyMarkers = [
                'Generated by Omnify',
                'omnify-generated',
                '* This file was auto-generated by Omnify',
                'Auto-generated by Omnify',
                '// Generated by omnify',
                '/* Generated by omnify',
                '@generated-by-omnify',
            ];

            foreach ($omnifyMarkers as $marker) {
                if (stripos($content, $marker) !== false) {
                    return true;
                }
            }

            // 保守的なアプローチ: 明確なマーカーがない場合は削除しない
            // これにより、ユーザーが手動で作成したseederファイルを誤って削除することを防ぐ
            return false;
        } catch (\Exception $e) {
            // ファイルが読み込めない場合は削除しない
            return false;
        }
    }

    /**
     * Run migrations if needed
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

        // 各出力行に色付きプレフィックスを追加
        $lines = explode("\n", $outputText);
        foreach ($lines as $line) {
            if (! empty(trim($line))) {
                $this->command->line('  <fg=blue>│</> ' . $line);
            }
        }

        $this->command->newLine();
        $this->command->info('✓ Migration completed successfully');
    }

    /**
     * Clean Omnify Base Models directory before copying new files
     */
    private function cleanOmnifyBaseModelsDirectory(array $fileList): array
    {
        $deletedFiles = [];

        // app/Models/OmnifyBase/ ディレクトリにコピーするファイルがあるかチェック
        $hasOmnifyBaseFiles = false;
        foreach ($fileList as $fileInfo) {
            if (
                str_starts_with($fileInfo['path'], 'app/Models/OmnifyBase/') ||
                str_starts_with($fileInfo['path'], 'laravel/app/Models/OmnifyBase/')
            ) {
                $hasOmnifyBaseFiles = true;
                break;
            }
        }

        if ($hasOmnifyBaseFiles) {
            $omnifyBaseDir = base_path('app/Models/OmnifyBase');

            if (File::exists($omnifyBaseDir)) {
                $this->command->info('Cleaning existing OmnifyBase Models directory');

                // 削除前に既存ファイルをリストアップ
                $existingFiles = File::allFiles($omnifyBaseDir);
                foreach ($existingFiles as $file) {
                    $deletedFiles[] = $file->getFilename();
                }

                File::deleteDirectory($omnifyBaseDir);
                $this->command->info('✓ app/Models/OmnifyBase directory cleaned');

                if ($this->command->getOutput()->isVerbose()) {
                    $this->command->info('  - ' . count($deletedFiles) . ' files deleted');
                }
            }
        }

        return $deletedFiles;
    }

    /**
     * Clean up temporary files
     * Note: This only removes temporary files, not .famm directory files like filelist.json
     */
    public function cleanup(): void
    {
        // Remove temporary ZIP file
        if (File::exists($this->tempZipFile)) {
            File::delete($this->tempZipFile);
        }

        // Remove temporary output directory (.temp)
        if (File::exists($this->outputDir)) {
            File::deleteDirectory($this->outputDir);
        }

        // Note: .famm directory and its contents (including filelist.json) are preserved
    }

    /**
     * Display an animated spinner with message while a task is processing
     */
    public function showSpinner(string $message, int $seconds = 3): void
    {
        $startTime = time();
        $frameCount = count($this->spinnerFrames);
        $i = 0;

        // 指定された秒数だけスピナーを表示
        while (time() - $startTime < $seconds) {
            $frame = $this->spinnerFrames[$i % $frameCount];
            $this->command->getOutput()->write("\r<fg=blue>{$frame}</> {$message}...");
            usleep(100000); // 0.1秒
            $i++;
        }

        // スピナーラインをクリア
        $this->command->getOutput()->write("\r" . str_repeat(' ', strlen($message) + 10) . "\r");
    }

    /**
     * Display an animated ellipsis while waiting
     */
    public function showWaitingDots(string $message, int $seconds = 3): void
    {
        $startTime = time();
        $dotFrames = ['', '.', '..', '...'];
        $i = 0;
        $frameCount = count($dotFrames);

        // 指定された秒数だけスピナーを表示
        while (time() - $startTime < $seconds) {
            $dots = $dotFrames[$i % $frameCount];
            $this->command->getOutput()->write("\r<fg=yellow>{$message}{$dots}</>");
            usleep(300000); // 0.3秒
            $i++;

            // ラインをクリア
            $this->command->getOutput()->write("\r" . str_repeat(' ', strlen($message) + 5));
        }

        // ラインをクリア
        $this->command->getOutput()->write("\r" . str_repeat(' ', strlen($message) + 5) . "\r");
    }

    /**
     * Create HTTP request with auth token
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

        if (File::exists(support_omnify_path('omnify.lock'))) {
            $request->attach(
                'omnify-lock',
                File::get(support_omnify_path('omnify.lock')),
                'omnify.lock'
            );
        }

        return $request;
    }

    /**
     * Create HTTP request with project secret
     */
    public function createProjectRequest(string $url, array $objects, bool $fresh, string $projectSecret): PendingRequest
    {
        $request = Http::timeout(600)
            ->acceptJson()
            ->withQueryParameters(['fresh' => $fresh])
            ->withHeader('x-project-secret', $projectSecret)
            ->withBody(json_encode($objects));

        if (File::exists(support_omnify_path('omnify.lock'))) {
            $request->attach('lock_file', support_omnify_path('omnify.lock'), 'omnify.lock');
        }

        return $request;
    }
}
