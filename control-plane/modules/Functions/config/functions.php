<?php

// Compose passes an empty KILN_IMAGE_PREFIX when the install's .env has none.
$prefix = rtrim((string) (env('KILN_IMAGE_PREFIX') ?: 'ghcr.io/othmanhaba'), '/');
$version = (string) (env('KILN_VERSION') ?: 'latest');
$tag = preg_match('/^v\d+\.\d+\.\d+/', $version) === 1 ? $version : 'latest';

return [
    // Runtimes a function can use. Their images follow the runtime convention in runtimes/functions/bun/README.md
    // (kiln-fn-install / kiln-fn-serve / kiln-fn-run) and are released with Kiln, so their tag is Kiln's version.
    // `family` picks the starters (ts: Bun, Node and Deno share them; python).
    'runtimes' => [
        'bun' => [
            'label' => 'Bun',
            'image' => env('KILN_FN_BUN_IMAGE', "{$prefix}/kiln-fn-bun:{$tag}"),
            'entrypoint' => 'index.ts',
            'language' => 'typescript',
            'family' => 'ts',
        ],
        'node' => [
            'label' => 'Node.js',
            'image' => env('KILN_FN_NODE_IMAGE', "{$prefix}/kiln-fn-node:{$tag}"),
            'entrypoint' => 'index.ts',
            'language' => 'typescript',
            'family' => 'ts',
        ],
        'deno' => [
            'label' => 'Deno',
            'image' => env('KILN_FN_DENO_IMAGE', "{$prefix}/kiln-fn-deno:{$tag}"),
            'entrypoint' => 'index.ts',
            'language' => 'typescript',
            'family' => 'ts',
        ],
        'python' => [
            'label' => 'Python',
            'image' => env('KILN_FN_PYTHON_IMAGE', "{$prefix}/kiln-fn-python:{$tag}"),
            'entrypoint' => 'main.py',
            'language' => 'python',
            'family' => 'python',
        ],
    ],

    'default_runtime' => 'bun',

    // Code limits per version.
    'max_bytes' => 1024 * 1024,
    'max_files' => 50,

    // New functions start with these; the Scaling settings move them within `bounds`.
    'defaults' => [
        'min_instances' => 0,
        'max_instances' => 5,
        'concurrency' => 50,
        'idle_timeout_s' => 300,
        'memory_mb' => 256,
        'cpus' => 0.5,
        'request_timeout_s' => 30,
    ],

    'bounds' => [
        'min_instances' => [0, 20],
        'max_instances' => [1, 50],
        'concurrency' => [1, 10000],
        'idle_timeout_s' => [10, 86400],
        'memory_mb' => [64, 16384],
        'cpus' => [0.1, 16],
        'request_timeout_s' => [1, 900],
    ],

    // How long an instance may take to accept connections before the request gets a 504.
    'start_timeout_s' => 30,
    'pids' => 256,
];
