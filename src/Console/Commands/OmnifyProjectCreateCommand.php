<?php

namespace FammSupport\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class OmnifyProjectCreateCommand extends Command
{
    protected $signature = 'omnify:project-create {--json : Output as JSON}';

    protected $description = 'Create a new project via Omnify API';

    private static string $endpoint = OmnifyLoginCommand::ENDPOINT;

    public function handle(): int
    {
        if (!OmnifyLoginCommand::verify()) {
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

        $result = $this->createProject($code, $name);

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

    /**
     * Create a new project via the API
     *
     * @param string $code
     * @param string $name
     * @return array|null
     */
    private function createProject(string $code, string $name): ?array
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
                ->post(self::$endpoint . '/api/create-project', [
                    'code' => $code,
                    'name' => $name
                ]);

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
}