<?php

namespace FammSupport\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class OmnifyProjectsCommand extends Command
{
    protected $signature = 'omnify:projects {--json : Output as JSON}';

    protected $description = 'Get list of projects from Omnify API';

    private static string $endpoint = OmnifyLoginCommand::ENDPOINT;

    public function handle(): int
    {
        if (!OmnifyLoginCommand::verify()) {
            $this->error('No valid authentication token found. Please run omnify:login to login.');
            return 1;
        }

        $projects = $this->getProjects();

        if (empty($projects)) {
            $this->error('Failed to retrieve projects or no projects available.');
            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode($projects, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->table(
            ['ID', 'Name', 'Secret', 'Created At'],
            $this->formatProjectsForTable($projects)
        );

        return 0;
    }

    /**
     * Get the list of projects from the API
     *
     * @return array|null
     */
    private function getProjects(): ?array
    {
        $authFile = omnify_path('.credentials');
        $content = File::get($authFile);
        $decoded = json_decode($content, true);
        $token = $decoded['token'] ?? '';

        // Remove timestamp for API request
        $tokenParts = explode('|', $token);
        array_pop($tokenParts);
        $accessToken = implode('|', $tokenParts);

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get(self::$endpoint . '/api/projects');

            if ($response->successful()) {
                return $response->json()['data'] ?? $response->json();
            }

            $this->error('API Error: ' . ($response->json()['message'] ?? 'Unknown error'));
            return null;
        } catch (\Exception $e) {
            $this->error('Connection Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Format projects data for console table output
     *
     * @param array $projects
     * @return array
     */
    private function formatProjectsForTable(array $projects): array
    {
        $tableData = [];

        foreach ($projects as $project) {
            $tableData[] = [
//                'id' => $project['id'] ?? 'N/A',
                'code' => $project['code'] ?? 'N/A',
                'name' => $project['name'] ?? 'N/A',
                'secret' => $project['secret'] ?? 'N/A',
                'created_at' => isset($project['created_at'])
                    ? Carbon::parse($project['created_at'])->format('Y-m-d H:i')
                    : 'N/A',
            ];
        }

        return $tableData;
    }
}