<?php

return [
    'github' => [
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'token' => env('GITHUB_TOKEN'),
        'owner' => env('GITHUB_OWNER'),
        'sdd_repo' => env('GITHUB_SDD_REPO'),
        'target_repos' => ['waltily', 'waltily-frontend'],
    ],

    'repo' => [
        'main_directory' => env('REPO_MAIN_DIRECTORY'),
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
        'workspace_path' => env('PREVIEW_WORKSPACE_PATH', '/var/www/sdd/workspaces'),
        'nginx_config_path' => env('NGINX_CONFIG_PATH', '/etc/nginx/sites-enabled'),
    ],

    'database' => [
        'dev_db_name' => env('DEV_DB_NAME', 'waltily'),
        'dev_db_host' => env('DEV_DB_HOST', '127.0.0.1'),
        'dev_db_user' => env('DEV_DB_USER', 'root'),
        'dev_db_password' => env('DEV_DB_PASSWORD', ''),
    ],
];
