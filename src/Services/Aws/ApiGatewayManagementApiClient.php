<?php

namespace FammSupport\Services\Aws;

use Aws\ApiGatewayManagementApi\ApiGatewayManagementApiClient as AwsApiGatewayManagementApiClient;
use Exception;
use Illuminate\Support\Facades\Log;

class ApiGatewayManagementApiClient
{
    private AwsApiGatewayManagementApiClient $client;

    public function __construct()
    {
        $endpoint = env('WEBSOCKET_MANAGEMENT_ENDPOINT');

        $this->client = new AwsApiGatewayManagementApiClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);
    }

    /**
     * Send message to a specific WebSocket connection
     *
     * @param string $connectionId The WebSocket connection ID
     * @param string $data The JSON string data to send
     * @return bool Success indicator
     */
    public function postToConnection(string $connectionId, string $data): bool
    {
        try {
            $this->client->postToConnection([
                'ConnectionId' => $connectionId,
                'Data' => $data
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to post to connection: ' . $e->getMessage(), [
                'connectionId' => $connectionId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Check if error is a stale connection (410 Gone)
     *
     * @param Exception $e The exception to check
     * @return bool True if connection is stale
     */
    public function isStaleConnection(Exception $e): bool
    {
        return str_contains($e->getMessage(), '410');
    }

    /**
     * Get the underlying AWS API Gateway Management API client
     *
     * @return AwsApiGatewayManagementApiClient
     */
    public function getClient(): AwsApiGatewayManagementApiClient
    {
        return $this->client;
    }
}
