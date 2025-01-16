<?php
$env = getenv('APP_ENV') ?? 'local';

$composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

if ($env === 'production') {
    $composerJson['repositories'] = [
        [
            'type' => 'vcs',
            'url' => 'https://github.com/Famgia/famm-support.git'
        ]
    ];
} else {
    $composerJson['repositories'] = [
        [
            'type' => 'path',
            'url' => '../famm-core'
        ]
    ];
}

file_put_contents(
    __DIR__ . '/../composer.json',
    json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);