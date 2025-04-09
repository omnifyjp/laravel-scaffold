<?php

namespace FammSupport\Services\Aws;

use Aws\Lambda\LambdaClient;

class LambdaService
{
    private LambdaClient $client;

    public function __construct()
    {
        $this->client = new LambdaClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);
    }

    public function invoke(array $args): \Aws\Result
    {
        return $this->client->invoke($args);
    }

}