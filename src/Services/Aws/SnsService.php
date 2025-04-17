<?php

namespace OmnifyJP\LaravelScaffold\Services\Aws;

use Aws\Sns\SnsClient;

class SnsService
{
    private SnsClient $client;

    public function __construct()
    {
        $this->client = new SnsClient([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }

    public function publish($params): \Aws\Result
    {
        return $this->client->publish($params);
    }
}
