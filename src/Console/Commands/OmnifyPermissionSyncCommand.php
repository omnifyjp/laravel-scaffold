<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
        $this->info('🔐 Permission Sync Disabled - Using YAML Schema System');
        $this->warn('⚠️  This command is deprecated. The system now uses YAML schemas for permissions.');
        $this->info('📝 Permissions are automatically generated from YAML schemas during omnify:build');
        $this->info('✅ No action needed. Permission system works through YAML schemas.');
        return;
    }
}
