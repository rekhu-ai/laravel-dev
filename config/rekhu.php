<?php

// T-Builder for Laravel. Everything here can be set from .env; `php artisan rekhu:install` writes the first three.
return [

    // The site key from the Rekhu account (Products → T-Builder for Laravel → Add app). One key per environment.
    'key' => env('REKHU_KEY'),

    // The secret the platform signs its calls with. Made by rekhu:install; never shared with anyone but the platform.
    'secret' => env('REKHU_SECRET'),

    // The platform. Only change it for a test platform.
    'platform' => env('REKHU_PLATFORM', 'https://api.rekhu.ai'),

    // How the platform reaches this app: "direct" (it calls https://your-app/rekhu/run) or "relay" (this app's
    // listener, php artisan rekhu:listen, collects the calls over its own outgoing connection). "auto" picks relay
    // for a local environment or an address the internet cannot reach, direct otherwise.
    'mode' => env('REKHU_MODE', 'auto'),

    // What the agent may change: "off" (read only), "branch" (writes go to a git branch of their own, the default
    // where the app has git), "on" (writes in the working copy, with a backup of every file). Production starts "off"
    // whatever this says, unless REKHU_PRODUCTION_WRITES=true.
    'writes' => env('REKHU_WRITES'),
    'production_writes' => (bool) env('REKHU_PRODUCTION_WRITES', false),

    // The path the platform calls in direct mode.
    'path' => env('REKHU_PATH', 'rekhu'),

    // Folders the agent may write in, relative to the app's root. Everything else is read only.
    'writable' => ['app', 'bootstrap/app.php', 'config', 'database', 'lang', 'resources', 'routes', 'tests'],

    // Never read, never written, whatever the request.
    'hidden' => ['.env', '.env.*', 'auth.json', 'storage/oauth-*.key', '*.pem', '*.key'],

    // Artisan commands the agent may run besides the read-only ones and make:*. Add your own here.
    'artisan' => ['cache:clear', 'config:clear', 'route:clear', 'view:clear', 'optimize', 'optimize:clear', 'queue:retry', 'queue:restart', 'route:cache', 'config:cache', 'view:cache', 'storage:link'],
];
