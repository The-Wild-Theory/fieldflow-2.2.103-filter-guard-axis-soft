<?php
$root = dirname(__DIR__);
$required = [
    'fieldflow.php',
    'readme.txt',
    'uninstall.php',
    'src/Support/Loader.php',
    'src/Support/Plugin.php',
    'src/Support/SystemHealth.php',
    'src/Support/LicenseManager.php',
    'src/Support/AdminNotices.php',
    'src/Support/Features.php',
];
$errors = [];
foreach ($required as $file) {
    if (!file_exists($root . DIRECTORY_SEPARATOR . $file)) {
        $errors[] = 'Ficheiro em falta: ' . $file;
    }
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if (!$file->isFile()) continue;
    $path = $file->getPathname();
    if (preg_match('/\.(bak|tmp)$/i', $path) || preg_match('/before/i', basename($path))) {
        $errors[] = 'Ficheiro indesejado no pacote: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    }
}
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($rii as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $cmd = 'php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0) {
        $errors[] = 'Lint falhou em ' . str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ': ' . implode("\n", $out);
    }
}
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
echo "QA smoke OK\n";
