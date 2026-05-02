<?php
// ── DB Connection ─────────────────────────────────────────────────────────
$host   = "webdev.iyaserver.com";
$userid = "xiuyuanq_orange";
$userpw = "orange37465";
$db     = "xiuyuanq_orangeDB";

$mysql = new mysqli($host, $userid, $userpw, $db);

if ($mysql->connect_errno) {
    echo json_encode(["error" => "DB connection error: " . $mysql->connect_error]);
    exit();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$task = isset($_REQUEST["task"]) ? $_REQUEST["task"] : "all";

// ── Helper: run query and return rows as array ────────────────────────────
function runQuery($mysql, $sql) {
    $results = $mysql->query($sql);
    if (!$results) {
        return ["error" => $mysql->error, "query" => $sql];
    }
    $rows = [];
    while ($row = $results->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

// Avoid empty HTTP body when json_encode() fails on bad UTF-8 in text columns.
function jsonOut($payload) {
    $flags = JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        echo json_encode(
            ["error" => "json_encode failed", "detail" => json_last_error_msg()],
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        return;
    }
    echo $json;
}

/** Dataset calendar range (must match map.html year chips). */
define('MAP_YEAR_MIN', 2007);
define('MAP_YEAR_MAX', 2016);

/** Approximate San Francisco city bounds (WGS84). Excludes bad/out-of-area GPS elsewhere in CA. */
define('MAP_SF_LAT_MIN', 37.708);
define('MAP_SF_LAT_MAX', 37.833);
define('MAP_SF_LNG_MIN', -122.518);
define('MAP_SF_LNG_MAX', -122.357);

/** Predicate with table alias (e.g. t). */
function mapSfBBoxSql(string $alias = 't'): string {
    return '
        AND ' . $alias . '.lat + 0 BETWEEN ' . MAP_SF_LAT_MIN . ' AND ' . MAP_SF_LAT_MAX . '
        AND ' . $alias . '.lng + 0 BETWEEN ' . MAP_SF_LNG_MIN . ' AND ' . MAP_SF_LNG_MAX . '
    ';
}

/** Same bbox for subqueries using bare column names (no alias). */
function mapSfBBoxSqlBare(): string {
    return '
        AND lat + 0 BETWEEN ' . MAP_SF_LAT_MIN . ' AND ' . MAP_SF_LAT_MAX . '
        AND lng + 0 BETWEEN ' . MAP_SF_LNG_MIN . ' AND ' . MAP_SF_LNG_MAX . '
    ';
}

/** GPS + district + inside SF bbox (+ optional calendar year). */
function mapEligibleWhereSql(?int $year = null): string {
    $sql = '
        t.lat IS NOT NULL AND t.lng IS NOT NULL
        AND t.lat != \'\' AND t.lng != \'\'
        AND t.district IS NOT NULL AND t.district != \'\'
    ' . mapSfBBoxSql('t');
    if ($year !== null) {
        $y = (int) $year;
        $sql .= "
        AND t.date IS NOT NULL AND t.date != ''
        AND YEAR(STR_TO_DATE(TRIM(t.date), '%m/%d/%Y')) = $y
    ";
    }

    return $sql;
}

/** District totals for share_of_total — same SF bbox + year scope as map rows. */
function mapShareSubqueries(?int $year): array {
    $extra = mapSfBBoxSqlBare();
    if ($year !== null) {
        $y = (int) $year;
        $extra .= "
            AND date IS NOT NULL AND date != ''
            AND YEAR(STR_TO_DATE(TRIM(date), '%m/%d/%Y')) = $y
        ";
    }
    $d = '
        SELECT district, COUNT(*) AS stop_count
        FROM ca_san_francisco_50k
        WHERE lat IS NOT NULL AND lng IS NOT NULL
          AND lat != \'\' AND lng != \'\'
          AND district IS NOT NULL AND district != \'\'
        ' . $extra . '
        GROUP BY district
    ';
    $tot = '
        SELECT COUNT(*) AS total_count
        FROM ca_san_francisco_50k
        WHERE lat IS NOT NULL AND lng IS NOT NULL
          AND lat != \'\' AND lng != \'\'
          AND district IS NOT NULL AND district != \'\'
        ' . $extra . '
    ';

    return [$d, $tot];
}

/** ROW_NUMBER / COUNT(*) OVER requires MySQL 8+ or MariaDB 10.2+ */
function mysqlSupportsWindowFunctions(string $version): bool {
    $version = trim($version);
    if ($version === '') {
        return true;
    }
    if (preg_match('/MariaDB/i', $version) && preg_match('/-(\d+)\.(\d+)/', $version, $m)) {
        $maj = (int) $m[1];
        $min = (int) $m[2];

        return ($maj > 10) || ($maj === 10 && $min >= 2);
    }
    if (preg_match('/^(\d+)/', $version, $m)) {
        return (int) $m[1] >= 8;
    }

    return true;
}

/**
 * Stratified sample (~1/stride per distinct TRIM(reason_for_stop)) without window functions.
 * Builds UNION ALL of per-reason ORDER BY raw_row_number LIMIT floor(n/stride) — same logic as ROW_NUMBER stratification.
 *
 * @return string|null Full SELECT … JOIN districts SQL, or null if every stratum has floor(n/stride)=0
 */
function buildStratifiedUnionMapSql(mysqli $mysql, int $stride, ?int $year = null): ?string {
    $where = mapEligibleWhereSql($year);
    $rbRes = $mysql->query("
        SELECT TRIM(IFNULL(t.reason_for_stop, '')) AS reason_raw, COUNT(*) AS n
        FROM ca_san_francisco_50k t
        WHERE $where
        GROUP BY TRIM(IFNULL(t.reason_for_stop, ''))
    ");
    if (!$rbRes) {
        return null;
    }
    // MySQL: ORDER BY in UNION branches must be inside a derived table — "Incorrect usage of UNION and ORDER BY" otherwise.
    $parts = [];
    $subIdx   = 0;
    while ($row = $rbRes->fetch_assoc()) {
        $reasonKey = isset($row['reason_raw']) ? (string) $row['reason_raw'] : '';
        $n          = (int) ($row['n'] ?? 0);
        $take       = (int) floor($n / $stride);
        if ($take < 1) {
            continue;
        }
        if ($reasonKey === '') {
            $pred = "TRIM(IFNULL(t.reason_for_stop, '')) = ''";
        } else {
            $pred = "TRIM(IFNULL(t.reason_for_stop, '')) = '" . $mysql->real_escape_string($reasonKey) . "'";
        }
        $takeSql = (int) $take;
        $alias   = 'strat_sub_' . $subIdx;
        $subIdx++;
        $parts[] = "
            SELECT * FROM (
                SELECT
                    t.raw_row_number,
                    t.lat,
                    t.lng,
                    t.location,
                    t.district,
                    t.date,
                    t.time,
                    t.reason_for_stop,
                    t.outcome
                FROM ca_san_francisco_50k t
                WHERE $where AND ($pred)
                ORDER BY t.raw_row_number
                LIMIT $takeSql
            ) AS $alias
        ";
    }
    if (empty($parts)) {
        return null;
    }
    $union             = implode("\nUNION ALL\n", $parts);
    [$dSql, $totSql] = mapShareSubqueries($year);

    return "
        SELECT
            w.raw_row_number,
            w.lat + 0 AS lat,
            w.lng + 0 AS lng,
            w.location,
            w.district,
            w.date,
            w.time,
            TRIM(IFNULL(w.reason_for_stop, '')) AS reason,
            w.outcome,
            FLOOR((w.time + 0) / 3600) AS hour,
            d.stop_count,
            ROUND(d.stop_count * 100.0 / total.total_count, 1) AS share_of_total
        FROM (
            $union
        ) w
        JOIN (
            $dSql
        ) d ON w.district = d.district
        CROSS JOIN (
            $totSql
        ) total
    ";
}

// ── TASK 1: Stops by District ─────────────────────────────────────────────
// date field is days since 1970-01-01 (R epoch); 2007 data has district codes
$sql_t1 = "
    SELECT 
        district AS district_code,
        COUNT(*) AS stop_count
    FROM ca_san_francisco_50k
    WHERE district != '' AND district IS NOT NULL
    GROUP BY district
    ORDER BY stop_count DESC
";

// ── TASK 2a: Stops by Hour of Day ─────────────────────────────────────────
// time field is still seconds since midnight; FLOOR(time/3600) = hour 0-23
// date column changed to real DATE but time column is unchanged
$sql_t2a = "
    SELECT 
        FLOOR(time / 3600) AS hour,
        COUNT(*) AS stop_count
    FROM ca_san_francisco_50k
    WHERE time IS NOT NULL AND time != ''
    GROUP BY hour
    ORDER BY hour ASC
";

// ── TASK 2b: Stops by Day of Week ─────────────────────────────────────────
// date is stored as M/D/YYYY string (e.g. '1/8/2012'), need STR_TO_DATE to parse
$sql_t2b = "
    SELECT 
        DAYNAME(STR_TO_DATE(date, '%m/%d/%Y')) AS weekday,
        DAYOFWEEK(STR_TO_DATE(date, '%m/%d/%Y')) AS weekday_num,
        COUNT(*) AS stop_count
    FROM ca_san_francisco_50k
    WHERE date IS NOT NULL AND date != ''
    GROUP BY weekday_num, weekday
    ORDER BY weekday_num ASC
";

// ── TASK 2c: Stops by Month ───────────────────────────────────────────────
// date is stored as M/D/YYYY string, need STR_TO_DATE to parse
$sql_t2c = "
    SELECT 
        MONTHNAME(STR_TO_DATE(date, '%m/%d/%Y')) AS month,
        MONTH(STR_TO_DATE(date, '%m/%d/%Y')) AS month_num,
        COUNT(*) AS stop_count
    FROM ca_san_francisco_50k
    WHERE date IS NOT NULL AND date != ''
    GROUP BY month_num, month
    ORDER BY month_num ASC
";

// ── TASK 3: Reason for Stop x Outcome ────────────────────────────────────
$sql_t3 = "
    SELECT
        reason_for_stop AS reason,
        COUNT(*) AS total,
        SUM(outcome = 'warning')  AS warning,
        SUM(outcome = 'citation') AS citation,
        SUM(outcome = 'arrest')   AS arrest,
        ROUND(100 * SUM(outcome = 'warning')  / COUNT(*), 1) AS warning_pct,
        ROUND(100 * SUM(outcome = 'citation') / COUNT(*), 1) AS citation_pct,
        ROUND(100 * SUM(outcome = 'arrest')   / COUNT(*), 1) AS arrest_pct,
        SUM(search_conducted = 'True') AS searches,
        SUM(contraband_found = 'True') AS contraband_hits,
        ROUND(
            100 * SUM(contraband_found = 'True')
            / NULLIF(SUM(search_conducted = 'True'), 0)
        , 1) AS hit_rate_pct
    FROM ca_san_francisco_50k
    WHERE outcome IN ('warning', 'citation', 'arrest')
      AND reason_for_stop != '' AND reason_for_stop IS NOT NULL
    GROUP BY reason_for_stop
    ORDER BY total DESC
";

// ── TASK 4a: Race x Outcome ───────────────────────────────────────────────
$sql_t4a = "
    SELECT
        subject_race AS race,
        COUNT(*) AS total,
        ROUND(100 * SUM(outcome = 'warning')  / COUNT(*), 1) AS warning_pct,
        ROUND(100 * SUM(outcome = 'citation') / COUNT(*), 1) AS citation_pct,
        ROUND(100 * SUM(outcome = 'arrest')   / COUNT(*), 1) AS arrest_pct
    FROM ca_san_francisco_50k
    WHERE outcome IN ('warning', 'citation', 'arrest')
      AND subject_race != '' AND subject_race IS NOT NULL
    GROUP BY subject_race
    ORDER BY total DESC
";

// ── TASK 4b: Age Group x Arrest Rate ─────────────────────────────────────
$sql_t4b = "
    SELECT
        CASE
            WHEN subject_age BETWEEN 18 AND 25 THEN '18-25'
            WHEN subject_age BETWEEN 26 AND 40 THEN '26-40'
            WHEN subject_age BETWEEN 41 AND 65 THEN '41-65'
            WHEN subject_age > 65             THEN '66+'
            ELSE 'Other'
        END AS age_group,
        COUNT(*) AS total,
        SUM(outcome = 'arrest') AS arrests,
        ROUND(100 * SUM(outcome = 'arrest') / COUNT(*), 1) AS arrest_pct
    FROM ca_san_francisco_50k
    WHERE outcome IN ('warning', 'citation', 'arrest')
      AND subject_age IS NOT NULL AND subject_age != ''
    GROUP BY age_group
    ORDER BY FIELD(age_group, '18-25', '26-40', '41-65', '66+', 'Other')
";

// ── Route and respond ─────────────────────────────────────────────────────
switch ($task) {
    case '1':
        echo json_encode(["task" => "stops_by_district",  "data" => runQuery($mysql, $sql_t1)]);
        break;
    case '2a':
        echo json_encode(["task" => "stops_by_hour",      "data" => runQuery($mysql, $sql_t2a)]);
        break;
    case '2b':
        echo json_encode(["task" => "stops_by_weekday",   "data" => runQuery($mysql, $sql_t2b)]);
        break;
    case '2c':
        echo json_encode(["task" => "stops_by_month",     "data" => runQuery($mysql, $sql_t2c)]);
        break;
    case '3':
        echo json_encode(["task" => "reason_vs_outcome",  "data" => runQuery($mysql, $sql_t3)]);
        break;
    case '4a':
        echo json_encode(["task" => "race_vs_outcome",    "data" => runQuery($mysql, $sql_t4a)]);
        break;
    case '4b':
        echo json_encode(["task" => "age_vs_arrest",      "data" => runQuery($mysql, $sql_t4b)]);
        break;
    case 'map':
        // Stratified sampling by TRIM(reason_for_stop): within each raw-reason stratum, keep the first
        // floor(stratum_size / stride) rows when ordered by raw_row_number —≈ a 1/stride slice of EVERY category,
        // so sample mix tracks population mix (unlike MOD(raw_row_number) on the whole table).
        // Requires MySQL 8+ (window functions). Optional: CREATE INDEX idx_sf50_reason ON ca_san_francisco_50k (reason_for_stop(64));
        // If a stratum has fewer than `stride` rows, floor(n/stride)=0 and it contributes no points (strict proportionality).
        $stride = isset($_REQUEST['stride']) ? max(1, min(100, (int) $_REQUEST['stride'])) : 5;

        $mapYear = null;
        if (isset($_REQUEST['year']) && $_REQUEST['year'] !== '' && strtolower((string) $_REQUEST['year']) !== 'all') {
            $y = (int) $_REQUEST['year'];
            if ($y >= MAP_YEAR_MIN && $y <= MAP_YEAR_MAX) {
                $mapYear = $y;
            }
        }

        $wMap               = mapEligibleWhereSql($mapYear);
        [$distJoinSql, $totalJoinSql] = mapShareSubqueries($mapYear);

        $cntRes = $mysql->query("
            SELECT COUNT(*) AS n
            FROM ca_san_francisco_50k t
            WHERE $wMap
        ");
        $totalEligible = 0;
        if ($cntRes) {
            $cr = $cntRes->fetch_assoc();
            $totalEligible = (int) ($cr['n'] ?? 0);
        }

        $mysql_version = '';
        $verRes          = $mysql->query('SELECT VERSION() AS v');
        if ($verRes) {
            $vr            = $verRes->fetch_assoc();
            $mysql_version = (string) ($vr['v'] ?? '');
        }

        $sql_stratified = "
            SELECT
                w.raw_row_number,
                w.lat + 0 AS lat,
                w.lng + 0 AS lng,
                w.location,
                w.district,
                w.date,
                w.time,
                TRIM(IFNULL(w.reason_for_stop, '')) AS reason,
                w.outcome,
                FLOOR((w.time + 0) / 3600) AS hour,
                d.stop_count,
                ROUND(d.stop_count * 100.0 / total.total_count, 1) AS share_of_total
            FROM (
                SELECT
                    t.raw_row_number,
                    t.lat,
                    t.lng,
                    t.location,
                    t.district,
                    t.date,
                    t.time,
                    t.reason_for_stop,
                    t.outcome,
                    ROW_NUMBER() OVER (
                        PARTITION BY TRIM(IFNULL(t.reason_for_stop, ''))
                        ORDER BY t.raw_row_number
                    ) AS rn_in_reason,
                    COUNT(*) OVER (
                        PARTITION BY TRIM(IFNULL(t.reason_for_stop, ''))
                    ) AS stratum_size
                FROM ca_san_francisco_50k t
                WHERE $wMap
            ) w
            JOIN (
                $distJoinSql
            ) d ON w.district = d.district
            CROSS JOIN (
                $totalJoinSql
            ) total
            WHERE w.rn_in_reason <= FLOOR(w.stratum_size / $stride)
        ";

        // MySQL 5.7 has no window functions — stratified query fails; fall back to MOD sampling.
        $sql_mod_fallback = "
            SELECT
                t.raw_row_number,
                t.lat + 0 AS lat,
                t.lng + 0 AS lng,
                t.location,
                t.district,
                t.date,
                t.time,
                TRIM(IFNULL(t.reason_for_stop, '')) AS reason,
                t.outcome,
                FLOOR((t.time + 0) / 3600) AS hour,
                d.stop_count,
                ROUND(d.stop_count * 100.0 / total.total_count, 1) AS share_of_total
            FROM ca_san_francisco_50k t
            JOIN (
                $distJoinSql
            ) d ON t.district = d.district
            CROSS JOIN (
                $totalJoinSql
            ) total
            WHERE $wMap
              AND MOD(t.raw_row_number, $stride) = 0
        ";

        $sampling_method          = 'stratified_reason_trimmed';
        $sampling_detail          = 'Partition by TRIM(reason_for_stop). Within each stratum of size n, keep rows with ROW_NUMBER <= floor(n/stride), ordered by raw_row_number (~1/stride per category). If n < stride, floor(n/stride)=0 so that stratum contributes no rows.';
        $sampling_fallback_note   = null;
        $stratified_windows_error = null;

        $attempt_window = mysqlSupportsWindowFunctions($mysql_version);
        $res            = false;
        $strat_win_err  = null;
        if ($attempt_window) {
            try {
                $res = $mysql->query($sql_stratified);
            } catch (Throwable $e) {
                $strat_win_err = $e->getMessage();
            }
            if ($res === false && $strat_win_err === null) {
                $strat_win_err = $mysql->error;
            }
        }
        if (!$res && $attempt_window) {
            $stratified_windows_error = $strat_win_err;
        }

        // MySQL 5.7: same stratification via UNION ALL + LIMIT per reason (no window functions).
        $strat_union_err = null;
        if (!$res) {
            $sql_union = buildStratifiedUnionMapSql($mysql, $stride, $mapYear);
            if ($sql_union !== null) {
                try {
                    $res = $mysql->query($sql_union);
                } catch (Throwable $e) {
                    $strat_union_err = $e->getMessage();
                }
                if ($res === false && $strat_union_err === null) {
                    $strat_union_err = $mysql->error;
                }
                if ($res) {
                    $sampling_method = 'stratified_reason_union';
                    $sampling_detail = 'Per distinct TRIM(reason_for_stop): UNION ALL of subqueries with ORDER BY raw_row_number LIMIT floor(n/stride). Same proportions as ROW_NUMBER stratification; works on MySQL 5.7.';
                }
            }
        }

        // Last resort: MOD on whole table (reason mix may diverge from population).
        if (!$res) {
            try {
                $res = $mysql->query($sql_mod_fallback);
            } catch (Throwable $e2) {
                jsonOut([
                    'task'  => 'map_points',
                    'error' => 'Map MOD fallback failed: ' . $e2->getMessage(),
                ]);
                break;
            }
            if (!$res) {
                jsonOut([
                    'task'   => 'map_points',
                    'error'  => 'Window stratified: ' . ($strat_win_err ?? '')
                        . ' | UNION stratified: ' . ($strat_union_err ?? 'no SQL or empty strata')
                        . ' | MOD fallback: ' . $mysql->error,
                ]);
                break;
            }
            $sampling_method        = 'mod_raw_row_fallback';
            $sampling_detail        = 'MOD(raw_row_number, stride): compatible with all MySQL versions; reason mix in the sample may differ from population.';
            $sampling_fallback_note = trim(
                ($strat_win_err ? 'ROW_NUMBER: ' . $strat_win_err . ' ' : '')
                . ($strat_union_err ? 'UNION stratified: ' . $strat_union_err : '')
            );
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $approxExpected = ($stride > 0 && $totalEligible > 0)
            ? (int) round($totalEligible / $stride)
            : count($rows);

        // Full-population counts per raw reason (same GPS+district rules as map rows) — use to verify rare categories vs sampling noise.
        $reasonBreakdown = [];
        $rbRes           = $mysql->query("
            SELECT TRIM(IFNULL(t.reason_for_stop, '')) AS reason_raw, COUNT(*) AS n
            FROM ca_san_francisco_50k t
            WHERE $wMap
            GROUP BY TRIM(IFNULL(t.reason_for_stop, ''))
            ORDER BY n DESC
        ");
        if ($rbRes) {
            while ($rb = $rbRes->fetch_assoc()) {
                $reasonBreakdown[] = $rb;
            }
        }

        jsonOut([
            'task' => 'map_points',
            'meta' => [
                'stride'                  => $stride,
                'year_filter'             => $mapYear,
                'row_count'               => count($rows),
                'total_eligible'          => $totalEligible,
                'eligible_denominator_note' => 'Denominator = stops inside SF city bbox with GPS + district (full count before ~1/stride stratified sample).',
                'approx_expected_rows'    => $approxExpected,
                'sampling'                => $sampling_method,
                'sampling_detail'         => $sampling_detail,
                'sampling_fallback_error' => $sampling_fallback_note,
                'stratified_windows_error'  => ($sampling_method === 'stratified_reason_union') ? $stratified_windows_error : null,
                'stratified_window_skipped' => ! $attempt_window,
                'mysql_version'             => $mysql_version,
                'reason_breakdown_gps'    => $reasonBreakdown,
            ],
            'data' => $rows,
        ]);
        break;
    default: // 'all'
        echo json_encode([
            "stops_by_district" => runQuery($mysql, $sql_t1),
            "stops_by_hour"     => runQuery($mysql, $sql_t2a),
            "stops_by_weekday"  => runQuery($mysql, $sql_t2b),
            "stops_by_month"    => runQuery($mysql, $sql_t2c),
            "reason_vs_outcome" => runQuery($mysql, $sql_t3),
            "race_vs_outcome"   => runQuery($mysql, $sql_t4a),
            "age_vs_arrest"     => runQuery($mysql, $sql_t4b),
        ]);
        break;
}

$mysql->close();
?>
