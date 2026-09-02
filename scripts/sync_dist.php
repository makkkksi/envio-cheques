<?php
$projectRoot = realpath(__DIR__ . '/..');
$distRoot = $projectRoot . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cheques_cobranza' . DIRECTORY_SEPARATOR . 'app';

$deploymentEntries = [
    'admin',
    'api',
    'config',
    'cron',
    'services',
    'libs',
    'scripts',
    'rendiciones',
    'LOGO-HOLDING-AUTOMARCO.png',
    'index.php',
    'index.html',
    'seller_session.js',
    'script.js',
    'styles.css',
];

function collectFiles(string $basePath, array $entries): array {
    $files = [];
    foreach ($entries as $entry) {
        $absolutePath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        if (is_file($absolutePath)) {
            $files[$entry] = $absolutePath;
            continue;
        }
        if (!is_dir($absolutePath)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $relative = substr($fileInfo->getPathname(), strlen($basePath) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[$relative] = $fileInfo->getPathname();
        }
    }
    return $files;
}

$rootFiles = collectFiles($projectRoot, $deploymentEntries);
$copied = 0;
foreach ($rootFiles as $relative => $sourcePath) {
    $targetPath = $distRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }
    if (!file_exists($targetPath) || hash_file('sha256', $sourcePath) !== hash_file('sha256', $targetPath)) {
        copy($sourcePath, $targetPath);
        $copied++;
    }
}

echo "Sincronizados {$copied} archivos a dist.\n";
