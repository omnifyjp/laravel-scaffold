<?php

namespace FammSupport\Services;

use Exception;
use FammSupport\Services\Aws\ApiGatewayManagementApiClient;
use FammSupport\Services\Aws\DynamoDBService;
use Illuminate\Support\Facades\Log;

class WebsocketPublisher
{
    /**
     * @throws Exception
     */
    public function publishMessage(string $topic, $message, string $event = 'message'): void
    {
        $timestamp = now()->getPreciseTimestamp(3);
        $dynamoDbClient = app(DynamoDBService::class);
        $apiGatewayClient = app(ApiGatewayManagementApiClient::class);

        $result = $dynamoDbClient->queryWithGSI(
            tableName: 'WebsocketConnectionTable',
            indexName: 'topic-index',
            keyConditionExpression: 'topic = :topic',
            expressionAttributeValues: [
                ':topic' => $topic,
            ],
        );

        $messageData = json_encode([
            'event' => $event,
            'topic' => $topic,
            'message' => $message,
            'timestamp' => $timestamp,
        ]);

        foreach ($result['items'] as $item) {
            $connectionId = $item['connectionId'];
            $res = $apiGatewayClient->postToConnection($connectionId, $messageData);
            if (! $res) {
                Log::error('Error:'.$connectionId);

                $dynamoDbClient->deleteItem('WebsocketConnectionTable', [
                    'connectionId' => $connectionId,
                ]);
            } else {
                Log::info('Sent:'.$connectionId);

            }
        }

        Log::info('postToConnection:'.$messageData);
    }
}
