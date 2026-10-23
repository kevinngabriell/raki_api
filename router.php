<?php
// Router for `php -S` (the deploy runs the built-in server with -t at the repo root, which
// would otherwise serve non-PHP files as-is). Denies internals; everything else is served normally.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$blocked = '#(^|/)\.(?!well-known/)'                              // dotfiles: .env, .git, .github ...
         . '|^/(storage|migrations|docs|vendor|core/tests|\.claude|\.opencode)(/|$)'
         . '|\.(md|sql|json|jsonl|log|lock|yml|yaml|sh)$#i';

if (preg_match($blocked, $path) || str_contains($path, '..')) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Not found';
    return true;
}

return false; // let the built-in server handle the request as usual
