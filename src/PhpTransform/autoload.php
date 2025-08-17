<?php
spl_autoload_register(function (string $class): void {
    // We map "OJS\" -> "src/"
    if (strncmp($class, 'OJS\\', 4) !== 0) return;
    $baseDir = __DIR__ . '/..';              // points to: <project>/src
    $relPath = str_replace('\\', '/', substr($class, 4)); // drop "OJS\"
    $file    = $baseDir . '/' . $relPath . '.php';
    if (is_file($file)) require_once $file;
});
