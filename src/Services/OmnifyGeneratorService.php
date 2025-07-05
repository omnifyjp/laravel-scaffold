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

    protected array $migrationStats = [
        'deleted' => [],
        'installed' => [],
        'skipped' => [],
        'exists' => [],
    ];

    /**
     * OmnifyGeneratorService constructor.
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
     */
    public function generateObjects(): array
    {
        $objects = [];
        foreach ([database_path('schemas'), support_path('database/schemas')] as $_directory) {
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
        File::deleteDirectory(omnify_path('app/Models/Base'));
        File::deleteDirectory(omnify_path('ts/Models/Base'));

        // .famm/database ディレクトリを完全に削除（古いファイルの残骸を防ぐため）
        $oldDatabasePath = omnify_path('database');
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

        $totalFiles = count($fileList);
        $this->command->info('Preparing for installation');
        $this->showSpinner('  Analyzing file structure', 2);

        // app/Models/OmnifyBase フォルダーの特別処理 - 完全に削除してから新しいファイルをコピー
        $deletedOmnifyBaseFiles = $this->cleanOmnifyBaseModelsDirectory($fileList);

        // Check for OmnifyBase files in filelist
        $omnifyBaseFiles = array_filter($fileList, function ($file) {
            return str_starts_with($file['path'], 'app/Models/OmnifyBase/') ||
                str_starts_with($file['path'], 'laravel/app/Models/OmnifyBase/');
        });

        if (!empty($omnifyBaseFiles)) {
            $this->command->info('🏗️  Found ' . count($omnifyBaseFiles) . ' OmnifyBase model(s) to process');
        }

        $this->command->info("Installing {$totalFiles} files");

        $progressBar = $this->command->getOutput()->createProgressBar($totalFiles);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $progressBar->start();

        $filesProcessed = 0;
        $filesSkipped = 0;
        $fileDetails = [];
        $factoryStats = [
            'installed' => [],
            'skipped' => [],
            'exists' => [],
        ];
        $copyStats = [
            'deleted' => $deletedOmnifyBaseFiles,
            'installed' => [],
            'skipped' => [],
            'exists' => [],
        ];
        $omnifyBaseStats = [
            'deleted' => $deletedOmnifyBaseFiles,
            'installed' => [],
            'skipped' => [],
            'exists' => [],
        ];

        foreach ($fileList as $fileInfo) {
            if (! isset($fileInfo['path']) || ! isset($fileInfo['replace'])) {
                $fileDetails[] = ['status' => 'warn', 'message' => 'Invalid file information was skipped.'];
                $progressBar->advance();

                continue;
            }

            $sourcePath = $this->outputDir . '/' . $fileInfo['path'];

            // 重要: database関連ファイルをLaravelの適切なディレクトリに直接移動
            // .famm/database/ には絶対にコピーしない（freshモード時のcleanup以外は触らない）
            if (str_starts_with($fileInfo['path'], 'database/')) {
                if (str_starts_with($fileInfo['path'], 'database/migrations/')) {
                    // database/migrations/ -> Laravel/database/migrations/omnify/
                    // Omnify専用のサブディレクトリに整理
                    $relativePath = str_replace('database/migrations/', '', $fileInfo['path']);

                    // 既に omnify/ が含まれている場合は重複を避ける
                    if (str_starts_with($relativePath, 'omnify/')) {
                        $targetPath = base_path("database/migrations/{$relativePath}");
                    } else {
                        $targetPath = base_path("database/migrations/omnify/{$relativePath}");
                    }
                } else {
                    // database/factories/ -> Laravel/database/factories/
                    // database/seeders/ -> Laravel/database/seeders/
                    $targetPath = base_path($fileInfo['path']);
                }
            } else {
                // 他のファイルは .famm/ ディレクトリに移動
                // ただし、OmnifyBase files は Laravel プロジェクトに直接移動
                if (str_starts_with($fileInfo['path'], 'app/Models/OmnifyBase/')) {
                    $targetPath = base_path($fileInfo['path']);
                } elseif (str_starts_with($fileInfo['path'], 'laravel/app/Models/OmnifyBase/')) {
                    // laravel/app/Models/OmnifyBase/ -> Laravel/app/Models/OmnifyBase/
                    $laravelPath = str_replace('laravel/', '', $fileInfo['path']);
                    $targetPath = base_path($laravelPath);
                } else {
                    $targetPath = $this->baseDir . '/' . $fileInfo['path'];
                }
            }

            // ファイルが存在しない場合はスキップ
            if (! File::exists($sourcePath)) {
                $fileDetails[] = ['status' => 'warn', 'message' => 'File not found: ' . $fileInfo['path']];
                $progressBar->advance();

                continue;
            }

            // ターゲットディレクトリを作成
            $targetDirectory = dirname($targetPath);
            if (! File::exists($targetDirectory)) {
                File::makeDirectory($targetDirectory, 0755, true, true);
            }

            // Factoryファイルの特別処理 - 存在しない場合のみコピー
            if (str_starts_with($fileInfo['path'], 'database/factories/')) {
                $fileName = basename($fileInfo['path']);

                if (! File::exists($targetPath)) {
                    File::copy($sourcePath, $targetPath, true);
                    $filesProcessed++;
                    $factoryStats['installed'][] = $fileName;
                    $fileDetails[] = ['status' => 'info', 'message' => 'Factory installed: ' . $fileInfo['path']];
                } else {
                    $filesSkipped++;
                    $factoryStats['exists'][] = $fileName;
                    $fileDetails[] = ['status' => 'warn', 'message' => 'Factory exists: ' . $fileInfo['path']];
                }
            } elseif (str_starts_with($fileInfo['path'], 'database/migrations/')) {
                // Migrationファイルの特別処理 - trackingのために
                $fileName = basename($fileInfo['path']);

                if ($fileInfo['replace'] || ! File::exists($targetPath)) {
                    File::copy($sourcePath, $targetPath, true);
                    $filesProcessed++;
                    $this->migrationStats['installed'][] = $fileName;
                    $fileDetails[] = ['status' => 'info', 'message' => 'Migration installed: ' . $fileInfo['path']];
                } else {
                    $filesSkipped++;
                    $this->migrationStats['exists'][] = $fileName;
                    $fileDetails[] = ['status' => 'warn', 'message' => 'Migration exists: ' . $fileInfo['path']];
                }
            } else {
                // 通常のファイル処理 - replaceフラグに基づく
                $fileName = basename($fileInfo['path']);

                if ($fileInfo['replace'] || ! File::exists($targetPath)) {
                    File::copy($sourcePath, $targetPath, true);
                    $filesProcessed++;

                    // OmnifyBase files と通常のファイルを区別してtrack
                    if (
                        str_starts_with($fileInfo['path'], 'app/Models/OmnifyBase/') ||
                        str_starts_with($fileInfo['path'], 'laravel/app/Models/OmnifyBase/')
                    ) {
                        $omnifyBaseStats['installed'][] = $fileName;
                        $copyStats['installed'][] = $fileName;
                        $fileDetails[] = ['status' => 'info', 'message' => 'OmnifyBase file installed: ' . $fileInfo['path']];
                    } else {
                        $copyStats['installed'][] = $fileName;
                        $fileDetails[] = ['status' => 'info', 'message' => 'File installed: ' . $fileInfo['path']];
                    }
                } else {
                    $filesSkipped++;

                    // OmnifyBase files と通常のファイルを区別してtrack
                    if (
                        str_starts_with($fileInfo['path'], 'app/Models/OmnifyBase/') ||
                        str_starts_with($fileInfo['path'], 'laravel/app/Models/OmnifyBase/')
                    ) {
                        $omnifyBaseStats['exists'][] = $fileName;
                        $copyStats['exists'][] = $fileName;
                        $fileDetails[] = ['status' => 'warn', 'message' => 'OmnifyBase file exists: ' . $fileInfo['path']];
                    } else {
                        $copyStats['skipped'][] = $fileName;
                        $fileDetails[] = ['status' => 'warn', 'message' => 'File skipped: ' . $fileInfo['path']];
                    }
                }
            }

            $progressBar->advance();
            usleep(5000); // Small delay for visual effect
        }

        $progressBar->finish();
        $this->command->newLine(2);

        // Show summary statistics
        $this->command->info('✓ Installation completed successfully');
        $this->command->info("  - {$filesProcessed} files installed");
        $this->command->info("  - {$filesSkipped} files skipped");

        // Factoryファイルの状態テーブルを表示
        $this->showFactoryStatusTable($factoryStats);

        // Migrationファイルの状態テーブルを表示
        $this->showMigrationStatusTable();

        // Copy Status テーブルを表示
        $this->showCopyStatusTable($copyStats);

        // OmnifyBase Model Status テーブルを表示
        $this->showOmnifyBaseStatusTable($omnifyBaseStats);

        // Only show detailed file information if verbosity is set higher
        if ($this->command->getOutput()->isVerbose()) {
            $this->command->newLine();
            $this->command->info('Detailed file information:');
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
     * Show factory files status table
     */
    public function showFactoryStatusTable(array $factoryStats): void
    {
        $factoriesPath = database_path('factories');

        if (! File::exists($factoriesPath)) {
            return; // ファクトリディレクトリが存在しない場合
        }

        // 現在のファクトリファイルをすべて取得
        $allFactoryFiles = File::files($factoriesPath);
        $allFileNames = array_map(function ($file) {
            return $file->getFilename();
        }, $allFactoryFiles);

        // 処理済みファイルを取得
        $processedFiles = array_merge(
            $factoryStats['installed'],
            $factoryStats['exists']
        );

        // 既存の未処理ファイルを特定（Omnifyに関係ないファイル）
        $untouchedFiles = array_diff($allFileNames, $processedFiles);

        $totalFactories = count($processedFiles) + count($untouchedFiles);

        if ($totalFactories === 0) {
            return; // ファクトリファイルがない場合は何も表示しない
        }

        $this->command->newLine();
        $this->command->info('📊 Factory Files Status');

        // テーブルデータを準備
        $tableData = [];

        // インストール済みファクトリ
        foreach ($factoryStats['installed'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '✅ Installed',
                'Action' => 'New omnify file created',
            ];
        }

        // 既存のファクトリ（スキップ済み）
        foreach ($factoryStats['exists'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '⚠️  Exists',
                'Action' => 'Omnify file skipped (already exists)',
            ];
        }

        // 既存の未処理ファイル（Omnifyと関係ない）
        foreach ($untouchedFiles as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '🔒 Preserved',
                'Action' => 'Existing file untouched',
            ];
        }

        // ファイル名でソート
        usort($tableData, function ($a, $b) {
            return strcmp($a['File'], $b['File']);
        });

        // テーブルを表示
        $this->command->table(
            ['File', 'Status', 'Action'],
            $tableData
        );

        // 統計情報を表示
        $installedCount = count($factoryStats['installed']);
        $existsCount = count($factoryStats['exists']);
        $preservedCount = count($untouchedFiles);

        $this->command->info("📈 Summary: {$installedCount} installed, {$existsCount} skipped, {$preservedCount} preserved");
    }

    /**
     * Show migration files status table
     */
    public function showMigrationStatusTable(): void
    {
        $omnifyMigrationsPath = database_path('migrations/omnify');

        if (! File::exists($omnifyMigrationsPath)) {
            return; // Omnifyマイグレーションディレクトリが存在しない場合
        }

        // 現在のOmnifyマイグレーションファイルをすべて取得
        $allMigrationFiles = File::files($omnifyMigrationsPath);
        $allFileNames = array_map(function ($file) {
            return $file->getFilename();
        }, $allMigrationFiles);

        // 処理済みファイルを取得
        $processedFiles = array_merge(
            $this->migrationStats['deleted'],
            $this->migrationStats['installed'],
            $this->migrationStats['exists']
        );

        // 既存の未処理ファイルを特定（Omnifyに関係ないファイル）
        $untouchedFiles = array_diff($allFileNames, $processedFiles);

        $totalMigrations = count($processedFiles) + count($untouchedFiles);

        if ($totalMigrations === 0) {
            return; // マイグレーションファイルがない場合は何も表示しない
        }

        $this->command->newLine();
        $this->command->info('🗂️  Omnify Migration Files Status (database/migrations/omnify/)');

        // テーブルデータを準備
        $tableData = [];

        // 削除されたマイグレーション
        foreach ($this->migrationStats['deleted'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '🗑️  Deleted',
                'Action' => 'Old omnify file removed (fresh mode)',
            ];
        }

        // インストール済みマイグレーション
        foreach ($this->migrationStats['installed'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '✅ Installed',
                'Action' => 'New omnify file created',
            ];
        }

        // 既存のマイグレーション（スキップ済み）
        foreach ($this->migrationStats['exists'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '⚠️  Exists',
                'Action' => 'Omnify file skipped (already exists)',
            ];
        }

        // 既存の未処理ファイル（Omnifyと関係ない）
        foreach ($untouchedFiles as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '🔒 Preserved',
                'Action' => 'Existing file untouched',
            ];
        }

        // ファイル名でソート
        usort($tableData, function ($a, $b) {
            return strcmp($a['File'], $b['File']);
        });

        // テーブルを表示
        $this->command->table(
            ['File', 'Status', 'Action'],
            $tableData
        );

        // 統計情報を表示
        $deletedCount = count($this->migrationStats['deleted']);
        $installedCount = count($this->migrationStats['installed']);
        $existsCount = count($this->migrationStats['exists']);
        $preservedCount = count($untouchedFiles);

        $this->command->info("📈 Summary: {$deletedCount} deleted, {$installedCount} installed, {$existsCount} skipped, {$preservedCount} preserved");
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

        // omnify専用ディレクトリの全ファイルを削除
        $omnifyMigrationFiles = File::glob($omnifyMigrationsPath . '/*.php');

        if (empty($omnifyMigrationFiles)) {
            $this->command->info('  No old omnify migration files found');

            return $deletedFiles;
        }

        foreach ($omnifyMigrationFiles as $filePath) {
            $fileName = basename($filePath);

            if (File::delete($filePath)) {
                $deletedFiles[] = $fileName;

                if ($this->command->getOutput()->isVerbose()) {
                    $this->command->info("  Deleted: {$fileName}");
                }
            }
        }

        $this->command->info('✓ Old omnify migration files cleaned');
        $this->command->info('  - ' . count($deletedFiles) . ' files deleted');

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
                '@generated-by-omnify'
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
     * Show copy files status table
     */
    public function showCopyStatusTable(array $copyStats): void
    {
        $totalFiles = count($copyStats['deleted']) + count($copyStats['installed']) + count($copyStats['skipped']) + count($copyStats['exists']);

        if ($totalFiles === 0) {
            return; // ファイルがない場合は何も表示しない
        }

        $this->command->newLine();
        $this->command->info('📄 File Copy Status');

        // テーブルデータを準備
        $tableData = [];

        // 削除されたファイル
        foreach ($copyStats['deleted'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '🗑️  Deleted',
                'Action' => 'Old OmnifyBase file removed',
            ];
        }

        // インストール済みファイル
        foreach ($copyStats['installed'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '✅ Installed',
                'Action' => 'New file copied successfully',
            ];
        }

        // 既存ファイル
        foreach ($copyStats['exists'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '📄 Exists',
                'Action' => 'File already exists (not replaced)',
            ];
        }

        // スキップされたファイル
        foreach ($copyStats['skipped'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '⏭️  Skipped',
                'Action' => 'File skipped (replace=false)',
            ];
        }

        // テーブルが空でない場合のみ表示
        if (! empty($tableData)) {
            $headers = ['File', 'Status', 'Action'];
            $this->command->table($headers, $tableData);
        }

        // 統計情報を表示
        $this->command->info('📊 Copy Summary:');
        $this->command->info("  - " . count($copyStats['deleted']) . " files deleted");
        $this->command->info("  - " . count($copyStats['installed']) . " files installed");
        $this->command->info("  - " . count($copyStats['exists']) . " files already exist");
        $this->command->info("  - " . count($copyStats['skipped']) . " files skipped");
    }

    /**
     * Show OmnifyBase models status table
     */
    public function showOmnifyBaseStatusTable(array $omnifyBaseStats): void
    {
        $totalFiles = count($omnifyBaseStats['deleted']) + count($omnifyBaseStats['installed']) + count($omnifyBaseStats['exists']);

        if ($totalFiles === 0) {
            return; // OmnifyBaseファイルがない場合は何も表示しない
        }

        $this->command->newLine();
        $this->command->info('🏗️  OmnifyBase Models Status (app/Models/OmnifyBase/)');

        // テーブルデータを準備
        $tableData = [];

        // 削除されたファイル
        foreach ($omnifyBaseStats['deleted'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '🗑️  Deleted',
                'Action' => 'Old OmnifyBase model removed (fresh replacement)',
            ];
        }

        // インストール済みファイル
        foreach ($omnifyBaseStats['installed'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '✅ Installed',
                'Action' => 'New OmnifyBase model created',
            ];
        }

        // 既存ファイル
        foreach ($omnifyBaseStats['exists'] as $fileName) {
            $tableData[] = [
                'File' => $fileName,
                'Status' => '📄 Exists',
                'Action' => 'OmnifyBase model already exists (not replaced)',
            ];
        }

        // テーブルが空でない場合のみ表示
        if (! empty($tableData)) {
            $headers = ['File', 'Status', 'Action'];
            $this->command->table($headers, $tableData);
        }

        // 統計情報を表示
        $this->command->info('📊 OmnifyBase Summary:');
        $this->command->info("  - " . count($omnifyBaseStats['deleted']) . " models deleted");
        $this->command->info("  - " . count($omnifyBaseStats['installed']) . " models installed");
        $this->command->info("  - " . count($omnifyBaseStats['exists']) . " models already exist");
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
