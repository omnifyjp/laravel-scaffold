<?php
$env = $argv[1] ?? null;

if (!$env) {
    if (file_exists(__DIR__ . '/../.env')) {
        $envContent = file_get_contents(__DIR__ . '/../.env');
        preg_match('/APP_ENV=(.*)/', $envContent, $matches);
        $env = $matches[1] ?? 'dev';
    } else {
        $env = 'dev';
    }
}

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