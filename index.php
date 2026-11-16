<?php

declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ReportService.php';

$cfg = app_config();
$assetV = (string) (@filemtime(__DIR__ . '/assets/style.css') ?: time());
$names = month_names();
$error = null;
$years = [(int) date('Y')];
$year = (int) ($_GET['year'] ?? date('Y'));
$data = null;

try {
    $svc = new ReportService();
    $years = $svc->availableYears();
    if (!in_array($year, $years, true)) {
        $year = $years[0];
    }
    $data = $svc->yearSummary($year);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$t = $data['totals'] ?? [
    'tasks' => 0, 'training' => 0, 'habits' => 0, 'goals' => 0,
    'milestones' => 0, 'horizons' => 0, 'hours_done' => 0, 'hours_total' => 0,
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($cfg['app_name']) ?> · <?= (int) $year ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&family=Sora:wght@500;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css?v=<?= e($assetV) ?>" />
</head>
<body>
  <main class="shell">
    <header class="topbar">
      <div>
        <p class="eyebrow"><?= e($cfg['source']) ?></p>
        <h1><?= e($cfg['app_name']) ?></h1>
        <p class="muted">Progreso personal del año y de cada mes, exportable a PDF.</p>
      </div>
      <div class="actions">
        <label>
          <select data-year-select aria-label="Año">
            <?php foreach ($years as $y): ?>
              <option value="<?= (int) $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= (int) $y ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <a class="btn btn-primary" href="pdf.php?scope=year&amp;year=<?= (int) $year ?>">PDF del año</a>
      </div>
    </header>

    <?php if ($error !== null): ?>
      <div class="alert">No se pudo leer la base <strong>lifequest</strong>: <?= e($error) ?></div>
    <?php else: ?>
      <section class="year-hero">
        <div class="year-hero-head">
          <p class="eyebrow">Total anual</p>
          <h2>Año <?= (int) $year ?></h2>
        </div>
        <div class="metrics">
          <div class="metric" data-tone="task"><span>Tareas hechas</span><strong><?= (int) $t['tasks'] ?></strong></div>
          <div class="metric" data-tone="sport"><span>Entrenamiento</span><strong><?= (int) $t['training'] ?></strong></div>
          <div class="metric" data-tone="habit"><span>Hábitos</span><strong><?= (int) $t['habits'] ?></strong></div>
          <div class="metric" data-tone="hours">
            <span>Horas hechas / prog.</span>
            <strong class="metric-stack">
              <em><?= e(format_hours_from_minutes((int) $t['hours_done'])) ?></em>
              <small>de <?= e(format_hours_from_minutes((int) $t['hours_total'])) ?></small>
            </strong>
          </div>
          <div class="metric" data-tone="goal"><span>Objetivos</span><strong><?= (int) $t['goals'] ?></strong></div>
          <div class="metric" data-tone="milestone"><span>Hitos</span><strong><?= (int) $t['milestones'] ?></strong></div>
          <div class="metric" data-tone="horizon"><span>Horizontes</span><strong><?= (int) $t['horizons'] ?></strong></div>
        </div>
      </section>

      <div class="section-title">
        <h2>Mes a mes</h2>
        <p class="muted">Cada card se puede imprimir en su propio PDF</p>
      </div>

      <section class="months">
        <?php foreach (($data['months'] ?? []) as $m => $row):
            $empty = ((int) $row['tasks'] + (int) $row['training'] + (int) $row['habits']
                + (int) $row['goals'] + (int) $row['milestones'] + (int) $row['horizons']) === 0;
            ?>
          <article class="month-card<?= $empty ? ' is-empty' : '' ?>">
            <header>
              <h3><?= e($names[$m] ?? ('Mes ' . $m)) ?></h3>
              <span class="muted"><?= (int) $year ?></span>
            </header>
            <div class="month-grid">
              <div class="chip" data-tone="task"><span>Tareas</span><strong><?= (int) $row['tasks'] ?></strong></div>
              <div class="chip" data-tone="sport"><span>Entreno</span><strong><?= (int) $row['training'] ?></strong></div>
              <div class="chip" data-tone="habit"><span>Hábitos</span><strong><?= (int) $row['habits'] ?></strong></div>
              <div class="chip" data-tone="hours">
                <span>Horas</span>
                <strong class="metric-stack">
                  <em><?= e(format_hours_from_minutes((int) $row['hours_done'])) ?></em>
                  <small>de <?= e(format_hours_from_minutes((int) $row['hours_total'])) ?></small>
                </strong>
              </div>
              <div class="chip" data-tone="goal"><span>Objetivos</span><strong><?= (int) $row['goals'] ?></strong></div>
              <div class="chip" data-tone="milestone"><span>Hitos</span><strong><?= (int) $row['milestones'] ?></strong></div>
              <div class="chip" data-tone="horizon"><span>Horizontes</span><strong><?= (int) $row['horizons'] ?></strong></div>
            </div>
            <?php if (!$empty): ?>
              <div class="month-actions">
                <a class="btn btn-primary" href="pdf.php?scope=month&amp;year=<?= (int) $year ?>&amp;month=<?= (int) $m ?>">PDF del mes</a>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </section>

      <?php $insights = $data['insights'] ?? []; $charts = $data['charts'] ?? null; if ($insights !== [] || $charts): ?>
        <section class="insights">
          <div class="section-title">
            <h2>Métricas del año</h2>
            <p class="muted">Promedios, picos y evolución mes a mes</p>
          </div>
          <?php if ($insights !== []): ?>
            <div class="insights-grid">
              <?php foreach ($insights as $item): ?>
                <article class="insight" data-tone="<?= e((string) $item['tone']) ?>">
                  <span><?= e((string) $item['label']) ?></span>
                  <strong><?= e((string) $item['value']) ?></strong>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($charts): ?>
            <div class="charts-grid">
              <article class="chart-card">
                <h3>Tareas, entreno y hábitos</h3>
                <canvas id="chartProgress" height="140"></canvas>
              </article>
              <article class="chart-card">
                <h3>Horas hechas vs programadas</h3>
                <canvas id="chartHours" height="140"></canvas>
              </article>
            </div>
            <script type="application/json" id="chart-data"><?= json_encode($charts, JSON_UNESCAPED_UNICODE) ?></script>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    <?php endif; ?>
  </main>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
  <script src="assets/app.js?v=<?= e($assetV) ?>"></script>
</body>
</html>
