<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Laravel Wisra Configuration
    |--------------------------------------------------------------------------
    |
    | This package injects HTML comments into compiled Blade templates showing
    | the view file path. Useful for debugging and IDE integration (e.g. Wisra).
    | Only active when enabled and in the local environment.
    |
    */

    'enabled' => env('LARAVEL_WISRA_ENABLED', true),

    'local_only' => env('LARAVEL_WISRA_LOCAL_ONLY', true),

    'skip_livewire' => env('LARAVEL_WISRA_SKIP_LIVEWIRE', true),

    'inject_context_meta_tags' => env('LARAVEL_WISRA_INJECT_CONTEXT_META_TAGS', true),
];
