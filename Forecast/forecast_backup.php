<?php
include '../PHP+MySQL/config.php';
date_default_timezone_set('Europe/Warsaw');
// Połączenie z bazą
$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("Błąd połączenia: " . mysqli_connect_error());
}

// Funkcje kolorowania
function getTempColor($temp) {
    if ($temp === null) return "#ffffff";
    if ($temp < 0) {
        $blue = min(255, max(0, 255 + $temp * 10));
        return "rgb($blue,$blue,255)";
    } else {
        $red = min(255, max(0, 255 - (40-$temp)*5));
        return "rgb(255,$red,$red)";
    }
}

function getHumColor($hum) {
    if ($hum === null) return "#ffffff";
    $r = 0;
    $g = max(100, 255 - $hum);
    $b = max(150, 255 - $hum/2);
    return "rgb($r,$g,$b)";
}

// Pobranie wszystkich lat
function getAvailableYears($conn) {
    $res = mysqli_query($conn, "SELECT DISTINCT YEAR(timestamp) as year FROM S_01 ORDER BY year DESC");
    $years = [];
    while($row = mysqli_fetch_assoc($res)){
        $years[] = $row['year'];
    }
    return $years;
}

// Pobranie danych godzinowych dla konkretnego dnia
function getDayData($conn, $date) {
    $stmt = mysqli_prepare($conn, "
        SELECT HOUR(timestamp) as hour, AVG(temperatureOUT) as temp, AVG(humidityOUT) as hum
        FROM S_01
        WHERE DATE(timestamp) = ?
        GROUP BY HOUR(timestamp)
        ORDER BY hour
    ");
    mysqli_stmt_bind_param($stmt, "s", $date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data = mysqli_fetch_all($result, MYSQLI_ASSOC);

    $fullDay = [];
    for ($h = 0; $h <= 23; $h++) {
        $found = false;
        foreach ($data as $row) {
            if ((int)$row['hour'] === $h) {
                $fullDay[$h] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) $fullDay[$h] = ['hour'=>$h, 'temp'=>null,'hum'=>null];
    }
    return array_values($fullDay);
}

// Pobranie średniej dziennej
function getDayAverage($conn, $date) {
    $stmt = mysqli_prepare($conn, "
        SELECT AVG(temperatureOUT) as temp, AVG(humidityOUT) as hum
        FROM S_01
        WHERE DATE(timestamp) = ?
    ");
    mysqli_stmt_bind_param($stmt, "s", $date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

// Generowanie tabeli
function generateTable($conn, $label, $type="temp") {
    $years = getAvailableYears($conn);
    echo "<h2>$label</h2>";

    $today = date("Y-m-d");
    for ($offset=0;$offset<=3;$offset++){
        $targetDate = date("m-d", strtotime("+$offset day"));
        $rowLabel = match($offset) {
            0=>"Dziś",1=>"Jutro",2=>"Pojutrze",3=>"Za 3 dni"
        };
        echo "<h3>$rowLabel</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse;'>";
        echo "<tr><th>Godzina</th>";
        $datesLabels = [];
        foreach($years as $year){
            $fullDate = $year."-".$targetDate;
            $datesLabels[$year] = date("d F Y", strtotime($fullDate));
            echo "<th>".$datesLabels[$year]."</th>";
        }
        echo "</tr>";

        for($h=0;$h<=23;$h++){
            echo "<tr><td>$h</td>";
            foreach($years as $year){
                $fullDate = $year."-".$targetDate;
                $dayData = getDayData($conn, $fullDate);
                $value = $dayData[$h][$type] ?? null;
                $color = $type=="temp"? getTempColor($value) : getHumColor($value);
                if($value !== null) $value = round($value,1);
                echo "<td style='background-color:$color;'>$value</td>";
            }
            echo "</tr>";
        }

        // Średnia dzienna
        echo "<tr><td>Średnia</td>";
        foreach($years as $year){
            $fullDate = $year."-".$targetDate;
            $avg = getDayAverage($conn, $fullDate);
            $value = $avg[$type] ?? null;
            $color = $type=="temp"? getTempColor($value) : getHumColor($value);
            if($value !== null) $value = round($value,1);
            echo "<td style='background-color:$color;'>$value</td>";
        }
        echo "</tr>";

        echo "</table><br>";
    }
}

// ==================
// Strona HTML
// ==================
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<title>Historia temperatury i wilgotności</title>
<style>
body{ font-family: Arial, sans-serif; margin: 20px; background:#f8f9fa;}
h2{ margin-top:40px; color:#333;}
h3{ margin-top:20px; color:#555;}
table{ margin:10px 0; width:100%; border:1px solid #ccc;}
th{ background:#ddd; }
td{ text-align:center;}
</style>
</head>
<body>
<h1>Historia temperatury i wilgotności</h1>

<?php
generateTable($conn,"Temperatura (°C)","temp");
generateTable($conn,"Wilgotność (%)","hum");

echo "<p>Wygenerowano: ".date("d-m-Y H:i:s")."</p>";
mysqli_close($conn);
?>
</body>
</html>
