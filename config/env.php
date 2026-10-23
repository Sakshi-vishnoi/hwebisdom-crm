<?php

function loadEnv($path)
{
    if (!file_exists($path) || !is_readable($path)) {
        return;
    }

    $lines = file(
        $path,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    foreach ($lines as $line) {

        $line = trim($line);

        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);

        $name = trim($name);
        $value = trim($value);

        if ($name === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (
                ($first === '"' && $last === '"') ||
                ($first === "'" && $last === "'")
            ) {
                $value = substr($value, 1, -1);
            }
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        putenv($name . '=' . $value);
    }
}

loadEnv(__DIR__ . '/../.env');