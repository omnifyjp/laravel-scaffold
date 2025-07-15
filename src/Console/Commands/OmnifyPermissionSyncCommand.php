<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\Permission;
use App\Models\PermissionGroup;

class OmnifyPermissionSyncCommand extends Command
{
    protected $signature = 'omnify:permission-sync {--force : Force sync without confirmation}';

    protected $description = 'Sync permissions to database from schema-lock.json';

    private array $actions = [
        'create',
        'read',
        'update',
        'delete',
        'list',
        'export',
        'import',
        'restore',
        'forceDelete',
        'viewAny',
        'view',
        'attach',
        'detach'
    ];

    public function handle(): void
    {
        $this->info('🔐 Starting Permission Sync Process...');

        // Check if schema-lock.json exists
        $schemaLockPath = base_path('.omnify/schema-lock.json');
        if (!File::exists($schemaLockPath)) {
            $this->error('❌ schema-lock.json file not found at: ' . $schemaLockPath);
            return;
        }

        // Read and parse schema-lock.json
        $this->info('📂 Reading schema-lock.json...');
        $schemaContent = File::get($schemaLockPath);
        $schemas = json_decode($schemaContent, true);

        if (!$schemas) {
            $this->error('❌ Invalid JSON in schema-lock.json');
            return;
        }

        $objects = array_keys($schemas);
        $totalPermissions = count($objects) * count($this->actions);

        $this->info("📊 Found " . count($objects) . " objects");
        $this->info("🎯 Will create/update {$totalPermissions} permissions");

        if (!$this->option('force')) {
            if (!$this->confirm('Do you want to continue?')) {
                $this->info('Operation cancelled.');
                return;
            }
        }

        // Ensure default permission group exists
        $this->info('🏷️  Ensuring default permission group exists...');
        $defaultGroup = PermissionGroup::updateOrCreate(
            ['name' => 'Object Permissions'],
            [
                'description' => 'Auto-generated permissions for object operations'
            ]
        );

        // Create progress bar
        $progressBar = $this->output->createProgressBar($totalPermissions);
        $progressBar->setFormat('verbose');
        $progressBar->start();

        $created = 0;
        $updated = 0;

        foreach ($objects as $objectName) {
            foreach ($this->actions as $action) {
                $permissionName = "objects.{$objectName}.{$action}";

                $permission = Permission::updateOrCreate(
                    ['name' => $permissionName],
                    [
                        '_group_id' => $defaultGroup->id,
                        'identifier' => $permissionName, // Set code to be same as name to avoid unique constraint
                        'description' => "Permission to {$action} {$objectName} objects"
                    ]
                );

                if ($permission->wasRecentlyCreated) {
                    $created++;
                } else {
                    $updated++;
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('✅ Permission sync completed!');
        $this->info("📈 Created: {$created} permissions");
        $this->info("🔄 Updated: {$updated} permissions");
        $this->info("📊 Total: " . ($created + $updated) . " permissions");

        $this->newLine();
        $this->info('🎉 All object permissions have been synced to the database!');
    }
}
