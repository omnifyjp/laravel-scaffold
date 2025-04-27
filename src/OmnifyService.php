<?php

namespace OmnifyJP\LaravelScaffold;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class OmnifyService
{
    const ENDPOINT = 'https://core.omnify.jp';
//    const ENDPOINT = 'http://famm-service.test';

    /**
     * Check if authentication token exists
     */
    public static function tokenExists(): bool
    {
        $authFile = omnify_path('.credentials');
        if (! File::exists($authFile)) {
            return false;
        }
        $content = File::get($authFile);
        $decoded = json_decode($content, true);

        return ! empty($decoded['token']);
    }

    /**
     * Verify if the current token is valid
     * @throws FileNotFoundException
     */
    public static function verify(): bool
    {
        if (! static::tokenExists()) {
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
                $expiryDate = Carbon::createFromTimestamp((int) $expiresAt);
                $now = Carbon::now();

                if ($now->gt($expiryDate)) {
                    if (File::exists($authFile)) {
                        File::delete($authFile);
                    }
                    throw new \Exception('Token has expired');
                }
            }

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get(self::ENDPOINT.'/api/me');

            return $response->successful() && isset($response->json()['id']);
        } catch (\Exception $e) {
            File::delete($authFile);

            return false;
        }
    }

    /**
     * Save authentication token
     */
    public static function saveToken(string $token): void
    {
        $authFile = omnify_path('.credentials');
        if (! File::exists(omnify_path())) {
            File::makeDirectory(omnify_path(), 0755, true);
        }

        $content = json_encode(['token' => $token]);
        File::put($authFile, $content);
    }

    /**
     * Get the authentication token for API requests
     */
    public static function getAccessToken(): ?string
    {
        if (! static::tokenExists()) {
            return null;
        }

        $authFile = omnify_path('.credentials');
        $content = File::get($authFile);
        $decoded = json_decode($content, true);
        $token = $decoded['token'] ?? '';

        // Remove timestamp for API request
        $tokenParts = explode('|', $token);
        array_pop($tokenParts);
        return implode('|', $tokenParts);
    }

    /**
     * Create a new authentication token
     */
    public static function createToken(string $email, string $password): ?string
    {
        try {
            $response = Http::asForm()
                ->acceptJson()
                ->post(self::ENDPOINT.'/api/create-token', [
                    'email' => $email,
                    'password' => $password,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['access_token'])) {
                    return $data['access_token'].'|'.$data['expires_at'];
                }
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the list of projects from the API
     */
    public static function getProjects(): ?array
    {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->get(self::ENDPOINT.'/api/projects');

            if ($response->successful()) {
                return $response->json()['data'] ?? $response->json();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create a new project via the API
     */
    public static function createProject(string $code, string $name): ?array
    {
        $accessToken = self::getAccessToken();
        if (!$accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post(self::ENDPOINT.'/api/create-project', [
                    'code' => $code,
                    'name' => $name,
                ]);

            if ($response->successful()) {
                return $response->json()['data'] ?? $response->json();
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
