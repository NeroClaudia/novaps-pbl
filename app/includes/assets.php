<?php

function asset(string $path): string
{
    $path = '/' . ltrim($path, '/');

    $file = BASE_PATH . '/public' . $path;

    if (!is_file($file)) {
        return $path;
    }

    $version = filemtime($file);

    return $version !== false
        ? $path . '?v=' . $version
        : $path;
}
