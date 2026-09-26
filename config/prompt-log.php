<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt Log Location
    |--------------------------------------------------------------------------
    |
    | Directory and file that App\Support\PromptLogger appends to. The
    | directory is relative to storage/ and is created on demand.
    |
    */

    'directory' => 'logs',

    'filename' => 'prompt.log',

    /*
    |--------------------------------------------------------------------------
    | Timestamp Format
    |--------------------------------------------------------------------------
    |
    | Prefix format for each entry, wrapped in square brackets.
    |
    */

    'timestamp_format' => 'Y-m-d H:i:s',

    /*
    |--------------------------------------------------------------------------
    | Rotation Threshold
    |--------------------------------------------------------------------------
    |
    | When the active log grows past this many bytes it is moved aside and a
    | fresh file is started. Set to 0 to keep a single unbounded file.
    |
    */

    'max_bytes' => (int) env('PROMPT_LOG_MAX_BYTES', 5 * 1024 * 1024),

];
