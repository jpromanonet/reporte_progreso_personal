<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ReportService
{
    private ?int $userId = null;

    public function userId(): int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }
        $stmt = db()->query(
            "SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
        );
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new RuntimeException('No hay usuarios activos en LifeQuest.');
        }
        $this->userId = $id;

        return $id;
    }

    /** @return list<int> */
    public function availableYears(): array
    {
        $uid = $this->userId();
        $years = [];
        $queries = [
            "SELECT DISTINCT YEAR(task_date) y FROM weekly_tasks WHERE user_id = {$uid} AND deleted_at IS NULL",
            "SELECT DISTINCT YEAR(log_date) y FROM habit_logs l
             INNER JOIN habits h ON h.id = l.habit_id AND h.user_id = {$uid}",
            "SELECT DISTINCT YEAR(completed_at) y FROM goals
             WHERE user_id = {$uid} AND deleted_at IS NULL AND completed_at IS NOT NULL",
            "SELECT DISTINCT YEAR(log_date) y FROM sport_logs WHERE user_id = {$uid}",
        ];
        foreach ($queries as $sql) {
            try {
                foreach (db()->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $y) {
                    $y = (int) $y;
                    if ($y >= 2000) {
                        $years[$y] = true;
                    }
                }
            } catch (Throwable) {
            }
        }
        try {
            foreach (db()->query(
                "SELECT DISTINCT YEAR(completed_at) y FROM milestones
                 WHERE user_id = {$uid} AND deleted_at IS NULL AND completed_at IS NOT NULL"
            )->fetchAll(PDO::FETCH_COLUMN) as $y) {
                $y = (int) $y;
                if ($y >= 2000) {
                    $years[$y] = true;
                }
            }
        } catch (Throwable) {
        }
        $years[(int) date('Y')] = true;
        $list = array_keys($years);
        rsort($list);

        return $list;
    }

    /** @return array{year:int,totals:array<string,float|int>,months:array<int,array<string,float|int>>} */
    public function yearSummary(int $year): array
    {
        $uid = $this->userId();
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = $this->emptyTotals();
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(task_date) m, COUNT(*) c FROM weekly_tasks
             WHERE user_id = :uid AND deleted_at IS NULL AND is_done = 1
               AND YEAR(task_date) = :y
             GROUP BY MONTH(task_date)",
            $year,
            $uid
        ) as $m => $c) {
            $months[$m]['tasks'] = $c;
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(l.log_date) m, COUNT(*) c FROM habit_logs l
             INNER JOIN habits h ON h.id = l.habit_id AND h.user_id = :uid AND h.deleted_at IS NULL
             WHERE YEAR(l.log_date) = :y AND l.status IN ('completed','partial')
             GROUP BY MONTH(l.log_date)",
            $year,
            $uid
        ) as $m => $c) {
            $months[$m]['habits'] = $c;
        }

        foreach ($this->completedByLocalMonth($year, $uid, false) as $m => $c) {
            $months[$m]['goals'] = $c;
        }
        foreach ($this->completedByLocalMonth($year, $uid, true) as $m => $c) {
            $months[$m]['horizons'] = $c;
        }
        foreach ($this->milestonesByLocalMonth($year, $uid) as $m => $c) {
            $months[$m]['milestones'] = $c;
        }

        // training: distance logs with qty>0 + attended classes + done exercises
        for ($m = 1; $m <= 12; $m++) {
            $months[$m]['training'] = $this->trainingCount($year, $m, $uid);
            $hours = $this->hoursForMonth($year, $m, $uid);
            $months[$m]['hours_done'] = $hours['done'];
            $months[$m]['hours_total'] = $hours['total'];
        }

        $totals = $this->emptyTotals();
        foreach ($months as $row) {
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return [
            'year' => $year,
            'totals' => $totals,
            'months' => $months,
            'insights' => $this->yearInsights($totals, $months),
            'charts' => $this->chartSeries($months),
        ];
    }

    /**
     * @param array<string,float|int> $totals
     * @param array<int,array<string,float|int>> $months
     * @return list<array{label:string,value:string,tone:string}>
     */
    public function yearInsights(array $totals, array $months): array
    {
        $names = month_names();
        $active = 0;
        $bestHours = -1;
        $bestHoursMonth = 0;
        $bestTasks = -1;
        $bestTasksMonth = 0;
        foreach ($months as $m => $row) {
            if (
                (int) $row['tasks'] > 0 || (int) $row['training'] > 0 || (int) $row['habits'] > 0
                || (int) $row['goals'] > 0 || (int) $row['milestones'] > 0 || (int) $row['horizons'] > 0
                || (int) $row['hours_done'] > 0
            ) {
                $active++;
            }
            if ((int) $row['hours_done'] > $bestHours) {
                $bestHours = (int) $row['hours_done'];
                $bestHoursMonth = (int) $m;
            }
            if ((int) $row['tasks'] > $bestTasks) {
                $bestTasks = (int) $row['tasks'];
                $bestTasksMonth = (int) $m;
            }
        }
        $div = max(1, $active);
        $hoursPct = (int) $totals['hours_total'] > 0
            ? round(((int) $totals['hours_done'] / (int) $totals['hours_total']) * 100)
            : 0;

        return [
            ['label' => 'Meses con datos', 'value' => (string) $active . ' / 12', 'tone' => 'task'],
            ['label' => 'Cumplimiento horas', 'value' => (string) $hoursPct . '%', 'tone' => 'hours'],
            ['label' => 'Prom. tareas / mes', 'value' => (string) round((int) $totals['tasks'] / $div, 1), 'tone' => 'task'],
            ['label' => 'Prom. hábitos / mes', 'value' => (string) round((int) $totals['habits'] / $div, 1), 'tone' => 'habit'],
            ['label' => 'Prom. entreno / mes', 'value' => (string) round((int) $totals['training'] / $div, 1), 'tone' => 'sport'],
            ['label' => 'Objetivos completados', 'value' => (string) (int) $totals['goals'], 'tone' => 'goal'],
            ['label' => 'Hitos completados', 'value' => (string) (int) $totals['milestones'], 'tone' => 'milestone'],
            ['label' => 'Horizontes completados', 'value' => (string) (int) $totals['horizons'], 'tone' => 'horizon'],
            ['label' => 'Mes con más horas', 'value' => $bestHoursMonth > 0 ? (($names[$bestHoursMonth] ?? '') . ' · ' . format_hours_from_minutes($bestHours)) : '—', 'tone' => 'hours'],
            ['label' => 'Mes con más tareas', 'value' => $bestTasksMonth > 0 ? (($names[$bestTasksMonth] ?? '') . ' · ' . $bestTasks) : '—', 'tone' => 'task'],
        ];
    }

    /**
     * @param array<string,float|int> $totals
     * @return list<array{label:string,value:string,tone:string}>
     */
    public function monthInsights(array $totals, int $year, int $month): array
    {
        $days = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('j');
        $hoursPct = (int) $totals['hours_total'] > 0
            ? round(((int) $totals['hours_done'] / (int) $totals['hours_total']) * 100)
            : 0;

        return [
            ['label' => 'Cumplimiento horas', 'value' => (string) $hoursPct . '%', 'tone' => 'hours'],
            ['label' => 'Tareas / día', 'value' => (string) round((int) $totals['tasks'] / max(1, $days), 1), 'tone' => 'task'],
            ['label' => 'Hábitos / día', 'value' => (string) round((int) $totals['habits'] / max(1, $days), 1), 'tone' => 'habit'],
            ['label' => 'Entrenos del mes', 'value' => (string) (int) $totals['training'], 'tone' => 'sport'],
            ['label' => 'Objetivos completados', 'value' => (string) (int) $totals['goals'], 'tone' => 'goal'],
            ['label' => 'Hitos completados', 'value' => (string) (int) $totals['milestones'], 'tone' => 'milestone'],
            ['label' => 'Horizontes completados', 'value' => (string) (int) $totals['horizons'], 'tone' => 'horizon'],
            ['label' => 'Horas hechas', 'value' => format_hours_from_minutes((int) $totals['hours_done']), 'tone' => 'hours'],
        ];
    }

    /**
     * @param array<int,array<string,float|int>> $months
     * @return array{labels:list<string>,tasks:list<int>,training:list<int>,habits:list<int>,hours_done:list<float>,hours_total:list<float>}
     */
    public function chartSeries(array $months): array
    {
        $short = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $out = [
            'labels' => [],
            'tasks' => [],
            'training' => [],
            'habits' => [],
            'hours_done' => [],
            'hours_total' => [],
        ];
        for ($m = 1; $m <= 12; $m++) {
            $row = $months[$m] ?? $this->emptyTotals();
            $out['labels'][] = $short[$m];
            $out['tasks'][] = (int) $row['tasks'];
            $out['training'][] = (int) $row['training'];
            $out['habits'][] = (int) $row['habits'];
            $out['hours_done'][] = round(((int) $row['hours_done']) / 60, 1);
            $out['hours_total'][] = round(((int) $row['hours_total']) / 60, 1);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function monthDetail(int $year, int $month): array
    {
        $uid = $this->userId();
        $summary = $this->yearSummary($year);
        $totals = $summary['months'][$month] ?? $this->emptyTotals();
        $startDay = sprintf('%04d-%02d-01', $year, $month);
        $endDay = (new DateTimeImmutable($startDay))->modify('last day of this month')->format('Y-m-d');
        $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
        $startUtc = (new DateTimeImmutable($startDay . ' 00:00:00', $tz))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $endUtc = (new DateTimeImmutable($endDay . ' 00:00:00', $tz))
            ->modify('+1 day')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $tasks = $this->fetchAll(
            "SELECT title, task_date, task_kind, is_done, estimated_minutes
             FROM weekly_tasks
             WHERE user_id = :uid AND deleted_at IS NULL
               AND task_date BETWEEN :a AND :b
             ORDER BY task_date, id",
            ['uid' => $uid, 'a' => $startDay, 'b' => $endDay]
        );

        $habits = $this->fetchAll(
            "SELECT h.name AS title, COUNT(l.id) AS times
             FROM habit_logs l
             INNER JOIN habits h ON h.id = l.habit_id AND h.user_id = :uid AND h.deleted_at IS NULL
             WHERE l.log_date BETWEEN :a AND :b AND l.status IN ('completed','partial')
             GROUP BY h.id, h.name
             ORDER BY times DESC, h.name",
            ['uid' => $uid, 'a' => $startDay, 'b' => $endDay]
        );

        $goals = $this->fetchAll(
            "SELECT title, completed_at FROM goals
             WHERE user_id = :uid AND deleted_at IS NULL AND completed_at IS NOT NULL
               AND horizon <> 'largo_plazo'
               AND (status = 'completed' OR progress_percent >= 100)
               AND completed_at >= :a AND completed_at < :b
             ORDER BY completed_at, title",
            ['uid' => $uid, 'a' => $startUtc, 'b' => $endUtc]
        );

        $horizons = $this->fetchAll(
            "SELECT title, completed_at FROM goals
             WHERE user_id = :uid AND deleted_at IS NULL AND completed_at IS NOT NULL
               AND horizon = 'largo_plazo'
               AND (status = 'completed' OR progress_percent >= 100)
               AND completed_at >= :a AND completed_at < :b
             ORDER BY completed_at, title",
            ['uid' => $uid, 'a' => $startUtc, 'b' => $endUtc]
        );

        $milestones = [];
        try {
            $milestones = $this->fetchAll(
                "SELECT title, completed_at FROM milestones
                 WHERE user_id = :uid AND deleted_at IS NULL AND is_done = 1
                   AND completed_at IS NOT NULL AND completed_at >= :a AND completed_at < :b
                 ORDER BY completed_at, title",
                ['uid' => $uid, 'a' => $startUtc, 'b' => $endUtc]
            );
        } catch (Throwable) {
        }

        $training = $this->trainingRows($year, $month, $uid);

        return [
            'year' => $year,
            'month' => $month,
            'totals' => $totals,
            'tasks' => $tasks,
            'habits' => $habits,
            'goals' => $goals,
            'milestones' => $milestones,
            'horizons' => $horizons,
            'training' => $training,
            'hours_label' => format_hours_from_minutes((int) $totals['hours_done'])
                . ' / '
                . format_hours_from_minutes((int) $totals['hours_total']),
            'insights' => $this->monthInsights($totals, $year, $month),
        ];
    }

    /** @return array<string,float|int> */
    private function emptyTotals(): array
    {
        return [
            'tasks' => 0,
            'training' => 0,
            'habits' => 0,
            'goals' => 0,
            'milestones' => 0,
            'horizons' => 0,
            'hours_done' => 0,
            'hours_total' => 0,
        ];
    }

    private function trainingCount(int $year, int $month, int $uid): int
    {
        return count($this->trainingRows($year, $month, $uid));
    }

    /** @return list<array{title:string,meta:string}> */
    private function trainingRows(int $year, int $month, int $uid): array
    {
        $startDay = sprintf('%04d-%02d-01', $year, $month);
        $endDay = (new DateTimeImmutable($startDay))->modify('last day of this month')->format('Y-m-d');
        $rows = [];
        try {
            $stmt = db()->prepare(
                "SELECT d.name, d.kind, l.log_date, l.quantity, l.attended
                 FROM sport_logs l
                 INNER JOIN sport_disciplines d ON d.id = l.discipline_id AND d.deleted_at IS NULL
                 WHERE l.user_id = :uid AND l.log_date BETWEEN :a AND :b
                 ORDER BY l.log_date, d.name"
            );
            $stmt->execute(['uid' => $uid, 'a' => $startDay, 'b' => $endDay]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $kind = (string) ($row['kind'] ?? '');
                if ($kind === 'distance') {
                    $qty = (float) ($row['quantity'] ?? 0);
                    if ($qty <= 0) {
                        continue;
                    }
                    $meta = number_format($qty, 1, ',', '.') . ' km · ' . date('d/m', strtotime((string) $row['log_date']));
                } else {
                    if (empty($row['attended'])) {
                        continue;
                    }
                    $meta = 'Clase · ' . date('d/m', strtotime((string) $row['log_date']));
                }
                $rows[] = ['title' => (string) $row['name'], 'meta' => $meta];
            }
        } catch (Throwable) {
        }
        try {
            $stmt = db()->prepare(
                "SELECT e.name, l.log_date, l.reps
                 FROM sport_exercise_logs l
                 INNER JOIN sport_exercises e ON e.id = l.exercise_id AND e.deleted_at IS NULL
                 WHERE l.user_id = :uid AND l.is_done = 1 AND l.log_date BETWEEN :a AND :b
                 ORDER BY l.log_date, e.sort_order, e.id"
            );
            $stmt->execute(['uid' => $uid, 'a' => $startDay, 'b' => $endDay]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $rows[] = [
                    'title' => (string) $row['name'],
                    'meta' => '×' . (int) ($row['reps'] ?? 0) . ' · ' . date('d/m', strtotime((string) $row['log_date'])),
                ];
            }
        } catch (Throwable) {
        }

        return $rows;
    }

    /** @return array<int,int> */
    private function completedByLocalMonth(int $year, int $uid, bool $horizons): array
    {
        $horizonSql = $horizons ? "AND horizon = 'largo_plazo'" : "AND horizon <> 'largo_plazo'";
        try {
            $stmt = db()->prepare(
                "SELECT completed_at FROM goals
                 WHERE user_id = :uid AND deleted_at IS NULL AND completed_at IS NOT NULL
                   {$horizonSql}
                   AND (status = 'completed' OR progress_percent >= 100)"
            );
            $stmt->execute(['uid' => $uid]);
            return $this->bucketLocalMonths($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [], $year);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int,int> */
    private function milestonesByLocalMonth(int $year, int $uid): array
    {
        try {
            $stmt = db()->prepare(
                "SELECT completed_at FROM milestones
                 WHERE user_id = :uid AND deleted_at IS NULL AND is_done = 1
                   AND completed_at IS NOT NULL"
            );
            $stmt->execute(['uid' => $uid]);
            return $this->bucketLocalMonths($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [], $year);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $utcTimestamps
     * @return array<int,int>
     */
    private function bucketLocalMonths(array $utcTimestamps, int $year): array
    {
        $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
        $utc = new DateTimeZone('UTC');
        $out = [];
        foreach ($utcTimestamps as $raw) {
            try {
                $local = (new DateTimeImmutable((string) $raw, $utc))->setTimezone($tz);
            } catch (Throwable) {
                continue;
            }
            if ((int) $local->format('Y') !== $year) {
                continue;
            }
            $m = (int) $local->format('n');
            $out[$m] = ($out[$m] ?? 0) + 1;
        }

        return $out;
    }

    /** @return array{done:int,total:int} */
    private function hoursForMonth(int $year, int $month, int $uid): array
    {
        $startDay = sprintf('%04d-%02d-01', $year, $month);
        $endDay = (new DateTimeImmutable($startDay))->modify('last day of this month')->format('Y-m-d');
        try {
            $stmt = db()->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN is_done = 1 THEN estimated_minutes ELSE 0 END),0) AS done_m,
                    COALESCE(SUM(estimated_minutes),0) AS total_m
                 FROM weekly_tasks
                 WHERE user_id = :uid AND deleted_at IS NULL
                   AND task_date BETWEEN :a AND :b"
            );
            $stmt->execute(['uid' => $uid, 'a' => $startDay, 'b' => $endDay]);
            $row = $stmt->fetch() ?: [];
            return [
                'done' => (int) ($row['done_m'] ?? 0),
                'total' => (int) ($row['total_m'] ?? 0),
            ];
        } catch (Throwable) {
            return ['done' => 0, 'total' => 0];
        }
    }

    /** @return array<int,int> */
    private function countByMonth(string $sql, int $year, int $uid): array
    {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(['y' => $year, 'uid' => $uid]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $m = (int) $row['m'];
                if ($m >= 1 && $m <= 12) {
                    $out[$m] = (int) $row['c'];
                }
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchAll(string $sql, array $params): array
    {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
