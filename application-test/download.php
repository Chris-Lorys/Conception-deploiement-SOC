<?php
$file = $_GET['file'] ?? 'public.txt';
$path = __DIR__ . '/files/' . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('Fichier introuvable');
}

header('Content-Type: text/plain; charset=utf-8');
readfile($path);
