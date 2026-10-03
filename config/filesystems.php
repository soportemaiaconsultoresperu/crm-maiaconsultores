<?php

use App\Support\Storage\StorageMode;

/*
|--------------------------------------------------------------------------
| Storage mode switch (AWS S3 vs local)
|--------------------------------------------------------------------------
|
| The entire switch is these three environment variables: `AWS_BUCKET`,
| `AWS_ACCESS_KEY_ID` and `AWS_SECRET_ACCESS_KEY`. When all three carry a
| non-blank value the `local`, `docs` and `public` disks below are backed by
| S3; when any one is missing or blank the same three disk names keep the
| project's local definitions, unchanged. Setting (or clearing) the three
| variables is the whole switch — there is no flag to flip in code.
|
| In cloud mode each disk reuses the local tree as its root prefix, so a
| relative path stored in the database means the same object in both modes:
| local -> `private`, docs -> `private/docs`, public -> `public`. The private
| disks keep private visibility and expose no public URL (ADR-011); only the
| `public` disk is served by direct URL.
|
*/

$usesAws = StorageMode::usesAws(
    env('AWS_BUCKET'),
    env('AWS_ACCESS_KEY_ID'),
    env('AWS_SECRET_ACCESS_KEY'),
);

// One S3 connection shared by the standalone `s3` disk and the three
// semantic disks, so they can never drift into two divergent definitions.
$s3Connection = [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
    'report' => false,
];

// Public assets are served by direct URL: prefer the configured bucket/CDN
// URL, otherwise the standard virtual-hosted bucket URL shape.
$publicBucketUrl = env('AWS_URL') ?: sprintf(
    'https://%s.s3.%s.amazonaws.com',
    env('AWS_BUCKET'),
    env('AWS_DEFAULT_REGION', 'us-east-1'),
);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Private file signed-URL lifetime
    |--------------------------------------------------------------------------
    |
    | How long, in seconds, a temporary URL handed to the browser stays valid.
    | Authorization is always decided before a URL is issued; this only bounds
    | how long the issued URL can outlive that decision. Keep it short.
    |
    */

    'temporary_url_ttl' => (int) env('FILESYSTEM_TEMPORARY_URL_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

'disks' => [

        'local' => $usesAws
            ? $s3Connection + ['root' => 'private', 'visibility' => 'private']
            : [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                'serve' => true,
                'throw' => false,
                'report' => false,
            ],

        // B09 — Documents live on a PRIVATE disk (ADR-011). No symlink
        // for documents: downloads are always authorized through
        // DocumentService and never exposed as a public URL.
        'docs' => $usesAws
            ? $s3Connection + ['root' => 'private/docs', 'visibility' => 'private']
            : [
                'driver' => 'local',
                'root' => storage_path('app/private/docs'),
                'visibility' => 'private',
                'throw' => false,
                'report' => false,
            ],

        'public' => $usesAws
            ? $s3Connection + ['root' => 'public', 'visibility' => 'public', 'url' => $publicBucketUrl]
            : [
                'driver' => 'local',
                'root' => storage_path('app/public'),
                'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
                'visibility' => 'public',
                'throw' => false,
                'report' => false,
            ],

        's3' => $s3Connection + [
            'url' => env('AWS_URL'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
