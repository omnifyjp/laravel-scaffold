<?php

/** @noinspection LaravelFunctionsInspection */

namespace OmnifyJP\LaravelScaffold\Console\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class OmnifyLoginCommand extends Command
{
    protected $signature = 'omnify:login';

    protected $description = 'Command description';
//        const ENDPOINT = 'http://famm-service.test';

    const ENDPOINT = 'https://core.omnify.jp';

    public function handle()
    {
        if (!static::tokenExists()) {
            $this->info('No authentication token found.');
            if ($this->promptLogin()) {
                $this->info('Login successful. Token saved.');

                return 0;
            } else {
                $this->error('Login failed.');

                return 1;
            }
        }

        $this->info('Authentication token exists.');
    }

    public static function tokenExists(): bool
    {
        $authFile = omnify_path('.credentials');
        if (!File::exists($authFile)) {
            return false;
        }
        $content = File::get($authFile);
        $decoded = json_decode($content, true);

        return !empty($decoded['token']);
    }

    /**
     * Prompt user for login credentials and get token
     */
    private function promptLogin(): bool
    {
        $email = $this->ask('Enter your email');
        $password = $this->secret('Enter your password');

        if (empty($email) || empty($password)) {
            $this->error('Email and password are required.');

            return false;
        }

        return $this->createToken($email, $password);
    }

    /**
     * Verify if the current token is valid by checking the /me endpoint
     */
    public static function verify(): bool
    {
        if (!static::tokenExists()) {
            return false;
        }

        $authFile = omnify_path('.credentials');
        $content = File::get($authFile);
        $decoded = json_decode($content, true);
        $token = $decoded['token'] ?? '';

        if (empty($token)) {
            return false;
        }

        try {
            $tokenParts = explode('|', $token);
            $expiresAt = end($tokenParts);
            array_pop($tokenParts);
            $accessToken = implode('|', $tokenParts);

            if (is_numeric($expiresAt)) {
                $expiryDate = Carbon::createFromTimestamp((int)$expiresAt);
                $now = Carbon::now();

                if ($now->gt($expiryDate)) {
                    if (File::exists($authFile)) {
                        File::delete($authFile);
                    }
                    throw new Exception('Token has expired');
                }
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get(self::ENDPOINT . '/api/me');

            return $response->successful() && isset($response->json()['id']);
        } catch (Exception $e) {
            File::delete($authFile);

            return false;
        }
    }

    /**
     * Create token by calling API endpoint
     */
    private function createToken(string $email, string $password): bool
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post(self::ENDPOINT . '/api/create-token', [
                    'email' => $email,
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['access_token'])) {
                    $this->saveToken($data['access_token'] . '|' . $data['expires_at']);

                    return true;
                }
            }

            $this->error('API Error: ' . ($response->json()['message'] ?? 'Unknown error'));

            return false;
        } catch (Exception $e) {
            $this->error('Connection Error: ' . $e->getMessage());

            return false;
        }
    }

    private function saveToken(string $token): void
    {
        $authFile = omnify_path('.credentials');
        if (!File::exists(omnify_path())) {
            File::makeDirectory(omnify_path(), 0755, true);
        }

        $content = json_encode(['token' => $token]);
        File::put($authFile, $content);
    }
}
