<?php

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use OmnifyJP\LaravelScaffold\OmnifyService;
use Illuminate\Console\Command;

class OmnifyProjectsCommand extends Command
{
    protected $signature = 'omnify:projects {--json : Output as JSON}';

    protected $description = 'Get list of projects from Omnify API';

    /**
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        if (!OmnifyService::verify()) {
            $this->error('No valid authentication token found. Please run omnify:login to login.');

            return 1;
        }

        $projects = OmnifyService::getProjects();

        if (empty($projects)) {
            $this->error('Failed to retrieve projects or no projects available.');

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($projects, JSON_PRETTY_PRINT));

            return 0;
        }

        $this->table(
            ['ID', 'Name', 'Secret', 'Build', 'Created At'],
            $this->formatProjectsForTable($projects)
        );

        return 0;
    }

    /**
     * Format projects data for console table output
     */
    private function formatProjectsForTable(array $projects): array
    {
        $tableData = [];

        foreach ($projects as $project) {
            $tableData[] = [
                'code' => $project['code'] ?? 'N/A',
                'name' => $project['name'] ?? 'N/A',
                'secret' => $project['secret'] ?? 'N/A',
                'build' => $project['build'] ?? 'N/A',
                'created_at' => isset($project['created_at'])
                    ? Carbon::parse($project['created_at'])->format('Y-m-d H:i')
                    : 'N/A',
            ];
        }

        return $tableData;
    }
}
