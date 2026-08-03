<?php

function ensure_monthly_archives_table($pdo)
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS monthly_archives (
                year_month CHAR(7) NOT NULL,
                activations INT UNSIGNED NOT NULL DEFAULT 0,
                sales DECIMAL(14,2) NOT NULL DEFAULT 0,
                collected DECIMAL(14,2) NOT NULL DEFAULT 0,
                cost DECIMAL(14,2) NOT NULL DEFAULT 0,
                profit DECIMAL(14,2) NOT NULL DEFAULT 0,
                debt DECIMAL(14,2) NOT NULL DEFAULT 0,
                archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (year_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $ready = true;
    } catch (Exception $e) {
        $ready = false;
    }
}

function compute_month_stats($pdo, $ym)
{
    $out = array(
        'activations' => 0,
        'sales' => 0.0,
        'collected' => 0.0,
        'cost' => 0.0,
        'profit' => 0.0,
        'debt' => 0.0,
    );

    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*), COALESCE(SUM(monthly_price),0)
             FROM subscriptions WHERE DATE_FORMAT(created_at, '%Y-%m') = :m"
        );
        $st->execute(array(':m' => $ym));
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row) {
            $out['activations'] = (int) $row[0];
            $out['sales'] = (float) $row[1];
        }
    } catch (Exception $e) {
    }

    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0),
                    COALESCE(SUM(cost_price),0),
                    COALESCE(SUM(profit),0)
             FROM invoices
             WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = :m"
        );
        $st->execute(array(':m' => $ym));
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row) {
            $out['collected'] = (float) $row[0];
            $out['cost'] = (float) $row[1];
            $out['profit'] = (float) $row[2];
        }
    } catch (Exception $e) {
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(SUM(amount),0) FROM invoices
                 WHERE status = 'paid' AND DATE_FORMAT(paid_at, '%Y-%m') = :m"
            );
            $st->execute(array(':m' => $ym));
            $out['collected'] = (float) $st->fetchColumn();
        } catch (Exception $e2) {
        }
    }

    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM invoices
             WHERE status = 'unpaid' AND DATE_FORMAT(due_date, '%Y-%m') = :m"
        );
        $st->execute(array(':m' => $ym));
        $out['debt'] = (float) $st->fetchColumn();
    } catch (Exception $e) {
    }

    return $out;
}

function archive_closed_months($pdo)
{
    try {
        ensure_monthly_archives_table($pdo);
        $current = date('Y-m');

        $first = null;
        $firstPaid = null;
        try {
            $first = $pdo->query(
                "SELECT DATE_FORMAT(MIN(created_at), '%Y-%m') FROM subscriptions WHERE created_at IS NOT NULL"
            )->fetchColumn();
        } catch (Exception $e) {
        }
        try {
            $firstPaid = $pdo->query(
                "SELECT DATE_FORMAT(MIN(paid_at), '%Y-%m') FROM invoices WHERE paid_at IS NOT NULL"
            )->fetchColumn();
        } catch (Exception $e) {
        }

        $start = null;
        if ($first && preg_match('/^\d{4}-\d{2}$/', $first)) {
            $start = $first;
        }
        if ($firstPaid && preg_match('/^\d{4}-\d{2}$/', $firstPaid)) {
            if ($start === null || $firstPaid < $start) {
                $start = $firstPaid;
            }
        }
        if ($start === null) {
            return;
        }

        $guard = 0;
        $cursor = $start;
        while ($cursor < $current && $guard < 240) {
            $guard++;
            try {
                $exists = $pdo->prepare('SELECT 1 FROM monthly_archives WHERE year_month = :m');
                $exists->execute(array(':m' => $cursor));
                if (!$exists->fetchColumn()) {
                    $stats = compute_month_stats($pdo, $cursor);
                    $ins = $pdo->prepare(
                        'INSERT INTO monthly_archives
                         (year_month, activations, sales, collected, cost, profit, debt, archived_at)
                         VALUES (:ym, :a, :s, :c, :cost, :p, :d, NOW())'
                    );
                    $ins->execute(array(
                        ':ym' => $cursor,
                        ':a' => $stats['activations'],
                        ':s' => $stats['sales'],
                        ':c' => $stats['collected'],
                        ':cost' => $stats['cost'],
                        ':p' => $stats['profit'],
                        ':d' => $stats['debt'],
                    ));
                }
            } catch (Exception $eMonth) {
                // أكمل الشهر التالي
            }
            $ts = strtotime($cursor . '-01');
            if ($ts === false) {
                break;
            }
            $next = date('Y-m', strtotime('+1 month', $ts));
            if ($next <= $cursor) {
                break;
            }
            $cursor = $next;
        }
    } catch (Exception $e) {
    }
}

function list_monthly_archives($pdo)
{
    try {
        ensure_monthly_archives_table($pdo);
        archive_closed_months($pdo);
        return $pdo->query(
            'SELECT * FROM monthly_archives ORDER BY year_month DESC'
        )->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

function get_month_archive($pdo, $ym)
{
    try {
        ensure_monthly_archives_table($pdo);
        $st = $pdo->prepare('SELECT * FROM monthly_archives WHERE year_month = :m');
        $st->execute(array(':m' => $ym));
        $row = $st->fetch();
        return $row ? $row : null;
    } catch (Exception $e) {
        return null;
    }
}
