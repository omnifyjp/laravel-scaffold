<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use OmnifyJP\LaravelScaffold\OmnifyService;

class OmnifyProjectCreateCommand extends Command
{
    protected $signature = 'omnify:project-create {--json : Output as JSON}';

    protected $description = 'Create a new project via Omnify API';

    public function handle(): int
    {
        if (! OmnifyService::verify()) {
            $this->error('No valid authentication token found. Please run omnify:login to login.');

            return 1;
        }

        $code = $this->ask('Enter project code:');
        $name = $this->ask('Enter project name:');

        if (empty($code) || empty($name)) {
            $this->error('Project code and name cannot be empty.');

            return 1;
        }

        $this->info("Creating project with code: {$code} and name: {$name}");

        $result = OmnifyService::createProject($code, $name);

        if (empty($result)) {
            $this->error('Failed to create project.');

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Project created successfully!');

        $this->table(
            ['Code', 'Name', 'Secret', 'Created At'],
            [[
                'code' => $result['code'] ?? 'N/A',
                'name' => $result['name'] ?? 'N/A',
                'secret' => $result['secret'] ?? 'N/A',
                'created_at' => isset($result['created_at'])
                    ? Carbon::parse($result['created_at'])->format('Y-m-d H:i')
                    : 'N/A',
            ]]
        );

        return 0;
    }
}
