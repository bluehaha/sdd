<?php

return [
    'workspace_path' => env('WORKSPACE_PATH'),

    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN'),
        'owner' => env('GITHUB_OWNER'),
        'sdd_repo' => env('GITHUB_SDD_REPO'),
        'target_repos' => ['waltily', 'waltily-frontend'],
    ],

    'slack' => [
        'webhook_url' => env('SLACK_WEBHOOK_URL'),
        'bot_token' => env('SLACK_BOT_TOKEN'),
    ],

    'claude' => [
        'binary_path' => env('CLAUDE_BINARY_PATH', 'claude'),
        'max_turns' => (int) env('CLAUDE_MAX_TURNS', 50),
        'timeout' => (int) env('CLAUDE_TIMEOUT', 3600),
    ],

    'preview' => [
        'domain' => env('PREVIEW_DOMAIN', 'dev.waltily.tw'),
    ],

    'database' => [
        'dev_db_name' => env('DEV_DB_NAME'),
        'dev_db_host' => env('DEV_DB_HOST'),
        'dev_db_user' => env('DEV_DB_USER'),
        'dev_db_password' => env('DEV_DB_PASSWORD'),
    ],
];
