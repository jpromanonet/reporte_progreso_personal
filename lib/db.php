<?php

declare(strict_types=1);

function app_config(): array
{
    static $cfg;
    if ($cfg === null) {
        $cfg = require dirname(__DIR__) . '/config.php';
    }

    return $cfg;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int) $c['port'],
        $c['name'],
        $c['charset']
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_hours_from_minutes(int $minutes): string
{
    $minutes = max(0, $minutes);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h > 0 && $m > 0) {
        return $h . 'h ' . $m . 'm';
    }
    if ($h > 0) {
        return $h . 'h';
    }

    return $m . 'm';
}

function month_names(): array
{
    return [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];
}

function month_slug(int $month): string
{
    $slugs = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    return $slugs[$month] ?? ('mes' . $month);
}
