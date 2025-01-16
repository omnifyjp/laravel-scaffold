<?php
$env = getenv('APP_ENV') ?? 'local';

$composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);

if ($env === 'production') {
    $composerJson['repositories'] = [
        [
            'type' => 'vcs',
            'url' => 'https://github.com/Famgia/famm-core.git',
            'options' => [
                "reference" => "main"
            ]
        ]
    ];
    $composerJson['require']['famm/core'] = 'dev-main';
} else {
    $composerJson['repositories'] = [
        [
            'type' => 'path',
            'url' => '../famm-core',
            'options' => [
                'symlink' => true
            ]
        ]
    ];
    $composerJson['require']['famm/core'] = '1.0.0';
}

file_put_contents(
    __DIR__ . '/../composer.json',
    json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);