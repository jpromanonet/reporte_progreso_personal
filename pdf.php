<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ReportService.php';
require_once __DIR__ . '/lib/SimplePdf.php';

$cfg = app_config();
$year = (int) ($_GET['year'] ?? date('Y'));
$scope = (string) ($_GET['scope'] ?? 'year');
$month = (int) ($_GET['month'] ?? 0);
$names = month_names();
$colors = [
    'task' => [0.37, 0.57, 0.53],
    'sport' => [0.77, 0.52, 0.42],
    'habit' => [0.72, 0.58, 0.31],
    'hours' => [0.44, 0.56, 0.68],
    'goal' => [0.56, 0.49, 0.67],
    'milestone' => [0.42, 0.60, 0.49],
    'horizon' => [0.54, 0.49, 0.44],
    'metrics' => [0.55, 0.42, 0.62], // solo el título "Métricas"
];

$appendInsights = static function (SimplePdf $pdf, array $insights, array $colors): void {
    if ($insights === []) {
        return;
    }
    $pdf->section('Métricas', $colors['metrics'], (string) count($insights));
    foreach ($insights as $item) {
        $pdf->item((string) $item['label'], (string) $item['value'], true);
    }
};

try {
    $svc = new ReportService();
    $pdf = new SimplePdf();
    $data = $svc->yearSummary($year);

    if ($scope === 'month' && $month >= 1 && $month <= 12) {
        $t = $data['months'][$month] ?? [
            'tasks' => 0, 'training' => 0, 'habits' => 0, 'goals' => 0,
            'milestones' => 0, 'horizons' => 0, 'hours_done' => 0, 'hours_total' => 0,
        ];
        $label = ($names[$month] ?? '') . ' ' . $year;
        $hoursLabel = format_hours_from_minutes((int) $t['hours_done'])
            . ' / '
            . format_hours_from_minutes((int) $t['hours_total']);
        $pdf->banner('Informe mensual personal', $cfg['source'] . '  ·  ' . $label, $label);
        $pdf->metricsWithTall(
            [
                ['label' => 'Tareas hechas', 'value' => (string) (int) $t['tasks'], 'color' => $colors['task']],
                ['label' => 'Entrenamiento', 'value' => (string) (int) $t['training'], 'color' => $colors['sport']],
                ['label' => 'Hábitos', 'value' => (string) (int) $t['habits'], 'color' => $colors['habit']],
                ['label' => 'Objetivos', 'value' => (string) (int) $t['goals'], 'color' => $colors['goal']],
                ['label' => 'Hitos', 'value' => (string) (int) $t['milestones'], 'color' => $colors['milestone']],
                ['label' => 'Horizontes', 'value' => (string) (int) $t['horizons'], 'color' => $colors['horizon']],
            ],
            [
                'label' => 'Horas hechas / programadas',
                'value' => $hoursLabel,
                'color' => $colors['hours'],
            ]
        );
        $appendInsights($pdf, $svc->monthInsights($t, $year, $month), $colors);
        $pdf->footer($cfg['app_name'] . ' · ' . $label);
        $filename = sprintf('reporte_%s_%d.pdf', month_slug($month), $year);
    } else {
        $t = $data['totals'];
        $hoursLabel = format_hours_from_minutes((int) $t['hours_done'])
            . ' / '
            . format_hours_from_minutes((int) $t['hours_total']);
        $pdf->banner('Informe anual personal', $cfg['source'] . '  ·  Año ' . $year, (string) $year);
        $pdf->metricsWithTall(
            [
                ['label' => 'Tareas hechas', 'value' => (string) (int) $t['tasks'], 'color' => $colors['task']],
                ['label' => 'Entrenamiento', 'value' => (string) (int) $t['training'], 'color' => $colors['sport']],
                ['label' => 'Hábitos', 'value' => (string) (int) $t['habits'], 'color' => $colors['habit']],
                ['label' => 'Objetivos', 'value' => (string) (int) $t['goals'], 'color' => $colors['goal']],
                ['label' => 'Hitos', 'value' => (string) (int) $t['milestones'], 'color' => $colors['milestone']],
                ['label' => 'Horizontes', 'value' => (string) (int) $t['horizons'], 'color' => $colors['horizon']],
            ],
            [
                'label' => 'Horas hechas / programadas',
                'value' => $hoursLabel,
                'color' => $colors['hours'],
            ]
        );
        $pdf->section('Mes a mes', $colors['task'], '12');
        foreach ($data['months'] as $m => $row) {
            $meta = sprintf(
                'Tareas %d · Entreno %d · Hábitos %d · Objetivos %d · Hitos %d · Horizontes %d · %s',
                (int) $row['tasks'],
                (int) $row['training'],
                (int) $row['habits'],
                (int) $row['goals'],
                (int) $row['milestones'],
                (int) $row['horizons'],
                format_hours_from_minutes((int) $row['hours_done']) . '/' . format_hours_from_minutes((int) $row['hours_total'])
            );
            $pdf->item(($names[$m] ?? ('Mes ' . $m)), $meta, true);
        }
        $appendInsights($pdf, $data['insights'] ?? [], $colors);
        $charts = $data['charts'] ?? null;
        if (is_array($charts)) {
            $pdf->barChart('Tareas por mes', $charts['labels'] ?? [], $charts['tasks'] ?? [], $colors['task']);
            $pdf->barChart('Hábitos por mes', $charts['labels'] ?? [], $charts['habits'] ?? [], $colors['habit']);
            $pdf->barChart('Horas hechas por mes', $charts['labels'] ?? [], $charts['hours_done'] ?? [], $colors['hours']);
        }
        $pdf->footer($cfg['app_name'] . ' · Año ' . $year);
        $filename = sprintf('reporte_%d.pdf', $year);
    }

    $bytes = $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF: ' . $e->getMessage();
    exit;
}
