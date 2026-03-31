<?php
/**
 * Weather Forecast API
 * Returns JSON with historical data + statistical forecast
 *
 * Method: weighted historical average (same date ±3 days across years)
 *         + recent 3-day trend extrapolation
 *         Blend: 70% historical, 30% trend
 *
 * Usage: forecast_api.php?days=4  (default 4 days: today + 3)
 */
include '../PHP+MySQL/config.php';
date_default_timezone_set('Europe/Warsaw');

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die(json_encode(['error' => 'DB connection failed']));
}

header('Content-Type: application/json');

$forecastDays = isset($_GET['days']) ? min((int)$_GET['days'], 7) : 4;

// ---- Get available years ----
$res = mysqli_query($conn, "SELECT DISTINCT YEAR(timestamp) as year FROM S_01 ORDER BY year DESC");
$years = [];
while ($row = mysqli_fetch_assoc($res)) {
    $years[] = (int)$row['year'];
}

// ---- Get current conditions (latest reading) ----
$res = mysqli_query($conn, "SELECT temperatureOUT, humidityOUT, temperatureIN, humidityIN, timestamp FROM S_01 ORDER BY timestamp DESC LIMIT 1");
$current = mysqli_fetch_assoc($res);

// ---- Get recent 3-day trend ----
$trendData = [];
for ($d = 3; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d day"));
    $stmt = mysqli_prepare($conn, "
        SELECT AVG(temperatureOUT) as avg_temp, AVG(humidityOUT) as avg_hum
        FROM S_01 WHERE DATE(timestamp) = ?
    ");
    mysqli_stmt_bind_param($stmt, "s", $date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $trendData[] = [
        'date' => $date,
        'temp' => $row['avg_temp'] !== null ? (float)$row['avg_temp'] : null,
        'hum'  => $row['avg_hum']  !== null ? (float)$row['avg_hum']  : null
    ];
    mysqli_stmt_close($stmt);
}

// Calculate trend (slope per day using simple linear regression)
function calcTrend($values) {
    $valid = [];
    foreach ($values as $i => $v) {
        if ($v !== null) $valid[] = ['x' => $i, 'y' => $v];
    }
    if (count($valid) < 2) return 0;

    $n = count($valid);
    $sx = $sy = $sxy = $sx2 = 0;
    foreach ($valid as $p) {
        $sx  += $p['x'];
        $sy  += $p['y'];
        $sxy += $p['x'] * $p['y'];
        $sx2 += $p['x'] * $p['x'];
    }
    $denom = $n * $sx2 - $sx * $sx;
    if ($denom == 0) return 0;
    return ($n * $sxy - $sx * $sy) / $denom;
}

$tempTrend = calcTrend(array_column($trendData, 'temp'));
$humTrend  = calcTrend(array_column($trendData, 'hum'));

// ---- Build forecast for each day ----
$output = [
    'current' => [
        'temp_out'  => (float)$current['temperatureOUT'],
        'hum_out'   => (float)$current['humidityOUT'],
        'temp_in'   => (float)$current['temperatureIN'],
        'hum_in'    => (float)$current['humidityIN'],
        'timestamp' => $current['timestamp']
    ],
    'trend' => [
        'temp_per_day' => round($tempTrend, 2),
        'hum_per_day'  => round($humTrend, 2),
        'recent_days'  => $trendData
    ],
    'days' => []
];

for ($offset = 0; $offset < $forecastDays; $offset++) {
    $targetMD = date("m-d", strtotime("+$offset day"));
    $targetDate = date("Y-m-d", strtotime("+$offset day"));

    $dayLabel = match($offset) {
        0 => "Today",
        1 => "Tomorrow",
        2 => "In 2 days",
        default => "In $offset days"
    };

    // ---- Historical data: same date ±3 days in all previous years ----
    $historicalByYear = [];
    $weightedTempSum = 0;
    $weightedHumSum  = 0;
    $weightTotal = 0;

    foreach ($years as $year) {
        $fullDate = $year . "-" . $targetMD;

        // Get hourly data for this date
        $stmt = mysqli_prepare($conn, "
            SELECT HOUR(timestamp) as hour,
                   AVG(temperatureOUT) as temp,
                   AVG(humidityOUT) as hum
            FROM S_01
            WHERE DATE(timestamp) BETWEEN DATE_SUB(?, INTERVAL 3 DAY)
                                      AND DATE_ADD(?, INTERVAL 3 DAY)
              AND HOUR(timestamp) = HOUR(timestamp)
            GROUP BY HOUR(timestamp)
            ORDER BY hour
        ");
        // For hourly breakdown, use exact date
        $stmtExact = mysqli_prepare($conn, "
            SELECT HOUR(timestamp) as hour,
                   AVG(temperatureOUT) as temp,
                   AVG(humidityOUT) as hum
            FROM S_01
            WHERE DATE(timestamp) = ?
            GROUP BY HOUR(timestamp)
            ORDER BY hour
        ");
        mysqli_stmt_bind_param($stmtExact, "s", $fullDate);
        mysqli_stmt_execute($stmtExact);
        $result = mysqli_stmt_get_result($stmtExact);
        $hourlyRaw = mysqli_fetch_all($result, MYSQLI_ASSOC);
        mysqli_stmt_close($stmtExact);

        // Fill 24 hours
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $found = null;
            foreach ($hourlyRaw as $row) {
                if ((int)$row['hour'] === $h) {
                    $found = $row;
                    break;
                }
            }
            $hourly[$h] = [
                'hour' => $h,
                'temp' => $found ? round((float)$found['temp'], 1) : null,
                'hum'  => $found ? round((float)$found['hum'], 1) : null
            ];
        }

        // Daily average for this year (±3 day window for better stats)
        $stmtAvg = mysqli_prepare($conn, "
            SELECT AVG(temperatureOUT) as temp, AVG(humidityOUT) as hum
            FROM S_01
            WHERE DATE(timestamp) BETWEEN DATE_SUB(?, INTERVAL 3 DAY)
                                      AND DATE_ADD(?, INTERVAL 3 DAY)
        ");
        mysqli_stmt_bind_param($stmtAvg, "ss", $fullDate, $fullDate);
        mysqli_stmt_execute($stmtAvg);
        $avgResult = mysqli_stmt_get_result($stmtAvg);
        $avg = mysqli_fetch_assoc($avgResult);
        mysqli_stmt_close($stmtAvg);

        $yearTemp = $avg['temp'] !== null ? (float)$avg['temp'] : null;
        $yearHum  = $avg['hum']  !== null ? (float)$avg['hum']  : null;

        // Weight: recent years count more (year_index^2)
        $yearAge = date("Y") - $year;
        $weight = max(1, 10 - $yearAge * 2);

        if ($yearTemp !== null) {
            $weightedTempSum += $yearTemp * $weight;
            $weightedHumSum  += $yearHum  * $weight;
            $weightTotal     += $weight;
        }

        $historicalByYear[] = [
            'year'    => $year,
            'date'    => $fullDate,
            'avg_temp'=> $yearTemp !== null ? round($yearTemp, 1) : null,
            'avg_hum' => $yearHum  !== null ? round($yearHum, 1)  : null,
            'hourly'  => $hourly
        ];

        mysqli_stmt_close($stmt);
    }

    // ---- Calculate forecast ----
    $histTemp = $weightTotal > 0 ? $weightedTempSum / $weightTotal : null;
    $histHum  = $weightTotal > 0 ? $weightedHumSum  / $weightTotal : null;

    // Trend projection
    $trendTemp = ($trendData[3]['temp'] ?? $histTemp) + $tempTrend * ($offset + 1);
    $trendHum  = ($trendData[3]['hum']  ?? $histHum)  + $humTrend  * ($offset + 1);

    // Blend: 70% historical + 30% trend
    $forecastTemp = null;
    $forecastHum  = null;
    if ($histTemp !== null) {
        $forecastTemp = round($histTemp * 0.7 + $trendTemp * 0.3, 1);
        $forecastHum  = round(max(0, min(100, $histHum * 0.7 + $trendHum * 0.3)), 1);
    }

    // ---- Hourly forecast (weighted average of same hour across years) ----
    $hourlyForecast = [];
    for ($h = 0; $h < 24; $h++) {
        $hTempSum = 0; $hHumSum = 0; $hWeight = 0;
        foreach ($historicalByYear as $idx => $yearData) {
            $yearAge = date("Y") - $yearData['year'];
            $w = max(1, 10 - $yearAge * 2);
            $ht = $yearData['hourly'][$h]['temp'];
            $hh = $yearData['hourly'][$h]['hum'];
            if ($ht !== null) {
                $hTempSum += $ht * $w;
                $hHumSum  += $hh * $w;
                $hWeight  += $w;
            }
        }
        $fTemp = $hWeight > 0 ? round($hTempSum / $hWeight, 1) : null;
        $fHum  = $hWeight > 0 ? round(max(0, min(100, $hHumSum / $hWeight)), 1) : null;

        // Apply trend adjustment
        if ($fTemp !== null) {
            $fTemp = round($fTemp + $tempTrend * $offset * 0.3, 1);
            $fHum  = round(max(0, min(100, $fHum + $humTrend * $offset * 0.3)), 1);
        }

        $hourlyForecast[$h] = ['hour' => $h, 'temp' => $fTemp, 'hum' => $fHum];
    }

    $output['days'][] = [
        'date'       => $targetDate,
        'label'      => $dayLabel,
        'day_name'   => date('l', strtotime($targetDate)),
        'forecast'   => [
            'temp' => $forecastTemp,
            'hum'  => $forecastHum,
            'hourly' => $hourlyForecast,
            'method' => '70% weighted historical (±3d window) + 30% 3-day trend'
        ],
        'historical' => $historicalByYear
    ];
}

echo json_encode($output, JSON_PRETTY_PRINT);
mysqli_close($conn);
?>
