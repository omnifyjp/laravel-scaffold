<?php

namespace FammSupport\Services;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use Exception;
use Illuminate\Support\Facades\Log;

class DynamoDBService
{
    private DynamoDbClient $client;
    private Marshaler $marshaler;

    public function __construct()
    {
        $this->client = new DynamoDbClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);

        $this->marshaler = new Marshaler();
    }

    /**
     * Get item by primary key
     */
    public function getItem($tableName, $key): mixed
    {
        try {
            $result = $this->client->getItem([
                'TableName' => $tableName,
                'Key' => $this->marshaler->marshalItem($key)
            ]);

            return isset($result['Item'])
                ? $this->marshaler->unmarshalItem($result['Item'])
                : null;
        } catch (Exception $e) {
            \Log::error('DynamoDB getItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Query items using a condition
     */
    public function query($tableName, $keyConditionExpression, $expressionAttributeValues): array
    {
        try {
            $result = $this->client->query([
                'TableName' => $tableName,
                'KeyConditionExpression' => $keyConditionExpression,
                'ExpressionAttributeValues' => $this->marshaler->marshalJson(
                    json_encode($expressionAttributeValues)
                )
            ]);

            $items = [];
            foreach ($result['Items'] as $item) {
                $items[] = $this->marshaler->unmarshalItem($item);
            }

            return $items;
        } catch (Exception $e) {
            \Log::error('DynamoDB query error: ' . $e->getMessage());
            throw $e;
        }
    }
    public function queryWithGSI(
        string $tableName,
        string $indexName,
        string $keyConditionExpression,
        array $expressionAttributeValues,
        ?string $filterExpression = null,
        bool $scanIndexForward = true,
        ?int $limit = null,
        ?array $expressionAttributeNames = null
    ): array {
        try {
            $params = [
                'TableName' => $tableName,
                'IndexName' => $indexName,
                'KeyConditionExpression' => $keyConditionExpression,
                'ExpressionAttributeValues' => $this->marshaler->marshalJson(
                    json_encode($expressionAttributeValues)
                ),
                'ExpressionAttributeNames' => $expressionAttributeNames,
                'ScanIndexForward' => $scanIndexForward
            ];

            // Add optional parameters if provided
            if ($filterExpression) {
                $params['FilterExpression'] = $filterExpression;
            }

            if ($limit) {
                $params['Limit'] = $limit;
            }

            $result = $this->client->query($params);

            $items = [];
            foreach ($result['Items'] as $item) {
                $items[] = $this->marshaler->unmarshalItem($item);
            }

            return $items;
        } catch (Exception $e) {
            Log::error('DynamoDB GSI query error: ' . $e->getMessage());
            throw $e;
        }
    }



    /**
     * Put a new item
     * @throws Exception
     */
    public function putItem($tableName, $item): true
    {
        try {
            $this->client->putItem([
                'TableName' => $tableName,
                'Item' => $this->marshaler->marshalItem($item)
            ]);
            return true;
        } catch (Exception $e) {
            Log::error('DynamoDB putItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing item
     * @throws Exception
     */
    public function updateItem($tableName, $key, $updateExpression, $expressionAttributeValues): true
    {
        try {
            $this->client->updateItem([
                'TableName' => $tableName,
                'Key' => $this->marshaler->marshalItem($key),
                'UpdateExpression' => $updateExpression,
                'ExpressionAttributeValues' => $this->marshaler->marshalJson(
                    json_encode($expressionAttributeValues)
                ),
                'ReturnValues' => 'UPDATED_NEW'
            ]);
            return true;
        } catch (Exception $e) {
            Log::error('DynamoDB updateItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete an item
     * @throws Exception
     */
    public function deleteItem($tableName, $key): true
    {
        try {
            $this->client->deleteItem([
                'TableName' => $tableName,
                'Key' => $this->marshaler->marshalItem($key)
            ]);
            return true;
        } catch (Exception $e) {
            Log::error('DynamoDB deleteItem error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Scan table
     * @throws Exception
     */
    public function scan($tableName, $filterExpression = null, $expressionAttributeValues = null): array
    {
        try {
            $params = ['TableName' => $tableName];

            if ($filterExpression) {
                $params['FilterExpression'] = $filterExpression;
                $params['ExpressionAttributeValues'] = $this->marshaler->marshalJson(
                    json_encode($expressionAttributeValues)
                );
            }

            $result = $this->client->scan($params);

            $items = [];
            foreach ($result['Items'] as $item) {
                $items[] = $this->marshaler->unmarshalItem($item);
            }

            return $items;
        } catch (Exception $e) {
            Log::error('DynamoDB scan error: ' . $e->getMessage());
            throw $e;
        }
    }
}
