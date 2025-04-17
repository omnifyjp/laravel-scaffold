<?php /** @noinspection LaravelFunctionsInspection */

namespace FammSupport\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class OmnifyInstallCommand extends Command
{
    protected $signature = 'omnify:install {--migrate : Run migrate}  {--seed : Run seeder}  {--fresh : Drops all tables and re-runs all migrations}';

    protected $description = 'Command description';


    public function handle(): void
    {
        if (!OmnifyLoginCommand::verify()) {
            $this->info('No authentication token found.');
            return;
        }

        $seed = $this->option('seed');
        $fresh = $this->option('fresh');
        $migrate = $this->option('migrate');
        if (!($omnify_key = config('omnify.omnify_key'))) {
            $this->error('omnify.omnify_keyが見つかりませんでした。');
            return;
        }
        if (!($omnify_secret = config('omnify.omnify_secret'))) {
            $this->error('omnify.omnify_secretが見つかりませんでした。');
            return;
        }

        $objects = $this->generateObjects();

        $url = OmnifyLoginCommand::ENDPOINT . "/api/schema-generator/" . $omnify_key;

        $outputDir = omnify_path(".temp");
        $baseDir = omnify_path();
        File::makeDirectory($outputDir, 0755, true, true);
        $tempZipFile = omnify_path('.temp/temp.zip');

        try {
            $this->info("処理中...");
            $response = Http::timeout(600)
                ->acceptJson()
                ->withQueryParameters(['fresh' => $fresh])
                ->withHeader('x-project-secret', $omnify_secret)
                ->withBody(json_encode($objects))
                ->post($url);
            if ($response->failed()) {
                $body = json_decode($response->body(), 1);
                $this->error("失敗しました" );
                $this->warn(json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }

            // 一時ファイルとして保存
            File::put($tempZipFile, $response->body());
            $zip = new ZipArchive;
            if ($zip->open($tempZipFile) === TRUE) {
                $zip->extractTo($outputDir);
                $zip->close();
                $this->info("解凍が完了しました: {$outputDir}");
            } else {
                $this->error("ZIPファイルを開けませんでした。");
                return;
            }
            // 一時ファイルの削除
            File::delete($tempZipFile);

            // filelistの存在確認と処理
            $filelistPath = $outputDir . '/filelist.json';
            if (!File::exists($filelistPath)) {
                $this->error("filelist.jsonが見つかりませんでした。");
                return;
            }


            if ($fresh) {
                $this->info("ファイルを削除中...");
                File::deleteDirectory(omnify_path("database"));
                File::deleteDirectory(omnify_path("app/Models/Base"));
                File::deleteDirectory(omnify_path("ts/Models/Base"));
            }

            // ファイルを実際のディレクトリに移動
            $this->moveFilesBasedOnFileList($filelistPath, $outputDir, $baseDir);

            File::deleteDirectory($outputDir);

            if ($migrate) {
                $output = new BufferedOutput;
                $this->info("Run migrate");


                Artisan::call($fresh ? 'migrate:fresh' : 'migrate', [
                    '--force' => true,
                    '--seed' => $seed,
                ], $output);
                $this->info($output->fetch());
            }


            $this->info("処理が完了しました！");
            return;
        } catch (\Exception $e) {
            $this->error("エラー発生: " . $e->getMessage());

            // 一時ファイルが存在する場合は削除
            if (File::exists($tempZipFile)) {
                File::delete($tempZipFile);
            }
            return;
        }
    }

    private function generateObjects(): array
    {
        $objects = [];
        foreach ([database_path('schemas'), support_path('database/schemas')] as $_directory) {
            if (!File::exists($_directory)) continue;
            foreach (File::directories($_directory) as $directory) {
                foreach (File::allFiles($directory) as $file) {
                    if (File::exists($file->getRealPath()) && in_array($file->getExtension(), ['json', 'yaml', 'yml'])) {
                        $object = $file->getExtension() === 'json'
                            ? File::json($file)
                            : Yaml::parse(File::get($file));
                        $objectName = Str::chopEnd($file->getBasename(), '.' . $file->getExtension());
                        $objects[$objectName] = [
                            'objectName' => $objectName,
                            ...$object
                        ];
                    }
                }
            }
        }
        return $objects;
    }

    /**
     * filelistに基づいてファイルを移動する
     */
    protected function moveFilesBasedOnFileList(string $filelistPath, string $sourceDir, string $targetDir): void
    {
        $filelistContent = File::get($filelistPath);
        $filelist = json_decode($filelistContent, true);

        if (!is_array($filelist)) {
            $this->error("filelist.jsonの形式が無効です。");
            return;
        }

        $filesProcessed = 0;
        $filesSkipped = 0;

        foreach ($filelist as $fileInfo) {
            if (!isset($fileInfo['path']) || !isset($fileInfo['replace'])) {
                $this->warn("無効なファイル情報がスキップされました。");
                continue;
            }

            $sourcePath = $sourceDir . '/' . $fileInfo['path'];
            $targetPath = $targetDir . '/' . $fileInfo['path'];

            // ファイルが存在しない場合はスキップ
            if (!File::exists($sourcePath)) {
                $this->warn("ファイルが見つかりません: " . $fileInfo['path']);
                continue;
            }

            // ターゲットディレクトリの作成
            $targetDirectory = dirname($targetPath);
            if (!File::exists($targetDirectory)) {
                File::makeDirectory($targetDirectory, 0755, true, true);
            }

            // replaceフラグに基づいてファイルを移動
            if ($fileInfo['replace'] || !File::exists($targetPath)) {
                File::copy($sourcePath, $targetPath, true);
                $filesProcessed++;
                $this->info("ファイルをコピーしました: " . $fileInfo['path']);
            } else {
                $filesSkipped++;
                $this->warn("ファイルはスキップされました: " . $fileInfo['path']);
            }
        }
        $this->info("ファイル処理完了: 処理済み {$filesProcessed} 件、スキップ {$filesSkipped} 件");
    }

}