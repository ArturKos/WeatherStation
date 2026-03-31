// ============================================================
// WeatherCast - forecast.js
// ============================================================

var forecastData = null;
var forecastChart = null;
var trendChart = null;
var selectedDay = 0;
var selectedType = 'temp';

// ---- Fetch data ----
fetch('forecast_api.php?days=4')
    .then(function(r) { return r.json(); })
    .then(function(data) {
        forecastData = data;
        renderCurrentConditions(data.current);
        renderForecastCards(data.days);
        renderForecastChart(data.days, 'temp');
        renderTrendChart(data.trend);
        renderDaySelector(data.days);
        renderHourlyTable(data.days, 0);
        document.getElementById('generatedAt').textContent =
            'Last reading: ' + data.current.timestamp;
    })
    .catch(function(err) {
        console.error('Error:', err);
        document.getElementById('forecastCards').innerHTML =
            '<p style="color: var(--red);">Error loading forecast data</p>';
    });

// ---- Current conditions ----
function renderCurrentConditions(current) {
    var el = document.getElementById('currentConditions');
    el.innerHTML =
        '<div class="stat"><i class="fas fa-thermometer-half"></i>' +
        '<span class="value">' + current.temp_out.toFixed(1) + '°C</span>' +
        '<span class="label">outside</span></div>' +
        '<div class="stat"><i class="fas fa-droplet"></i>' +
        '<span class="value">' + current.hum_out.toFixed(0) + '%</span>' +
        '<span class="label">outside</span></div>' +
        '<div class="stat"><i class="fas fa-house"></i>' +
        '<span class="value">' + current.temp_in.toFixed(1) + '°C</span>' +
        '<span class="label">inside</span></div>' +
        '<div class="stat"><i class="fas fa-droplet"></i>' +
        '<span class="value">' + current.hum_in.toFixed(0) + '%</span>' +
        '<span class="label">inside</span></div>';
}

// ---- Temperature class ----
function tempClass(temp) {
    if (temp === null) return '';
    if (temp < 0) return 'freezing';
    if (temp < 10) return 'cold';
    if (temp < 25) return 'warm';
    return 'hot';
}

// ---- Color for table cells ----
function tempColor(temp) {
    if (temp === null) return 'transparent';
    if (temp < 0) {
        var b = Math.min(255, Math.max(100, 200 + temp * 8));
        return 'rgba(' + b + ',' + b + ',255,0.3)';
    }
    var r = Math.min(255, temp * 8 + 50);
    var g = Math.max(50, 200 - temp * 5);
    return 'rgba(' + r + ',' + g + ',50,0.25)';
}

function humColor(hum) {
    if (hum === null) return 'transparent';
    var intensity = hum / 100 * 0.3;
    return 'rgba(69,170,242,' + intensity + ')';
}

// ---- Forecast cards ----
function renderForecastCards(days) {
    var container = document.getElementById('forecastCards');
    var html = '';

    days.forEach(function(day, idx) {
        var fc = day.forecast;
        var isToday = idx === 0;

        // Find hi/lo from hourly forecast
        var hi = -999, lo = 999;
        fc.hourly.forEach(function(h) {
            if (h.temp !== null) {
                if (h.temp > hi) hi = h.temp;
                if (h.temp < lo) lo = h.temp;
            }
        });

        var tempStr = fc.temp !== null ? fc.temp.toFixed(1) + '°' : '--';
        var humStr  = fc.hum  !== null ? fc.hum.toFixed(0) + '%' : '--';
        var hiStr   = hi > -999 ? hi.toFixed(1) + '°' : '--';
        var loStr   = lo < 999  ? lo.toFixed(1) + '°' : '--';

        html += '<div class="forecast-card' + (isToday ? ' today' : '') + '">';
        if (isToday) html += '<div class="card-badge">Today</div>';
        html += '<div class="card-header">' +
            '<span class="card-day">' + day.day_name + '</span>' +
            '<span class="card-date">' + day.date + '</span></div>';
        html += '<div class="card-temp ' + tempClass(fc.temp) + '">' + tempStr + '</div>';
        html += '<div class="card-hum"><i class="fas fa-droplet"></i> ' + humStr + '</div>';
        html += '<div class="card-range">' +
            '<span class="lo"><i class="fas fa-arrow-down"></i> ' + loStr + '</span>' +
            '<span class="hi"><i class="fas fa-arrow-up"></i> ' + hiStr + '</span></div>';
        html += '</div>';
    });

    container.innerHTML = html;
}

// ---- Forecast chart ----
function renderForecastChart(days, type) {
    var wrap = document.getElementById('forecastChartWrap');
    var msg = wrap.querySelector('.loading-msg');
    if (msg) msg.remove();
    var ctx = document.getElementById('forecastChart').getContext('2d');

    if (forecastChart) forecastChart.destroy();

    var datasets = [];
    var labels = Array.from({length: 24}, function(_, i) { return i + ':00'; });

    // Forecast line for each day
    var colors = ['#45aaf2', '#2ed573', '#ffa502', '#e94560'];
    days.forEach(function(day, idx) {
        var data = day.forecast.hourly.map(function(h) {
            return type === 'temp' ? h.temp : h.hum;
        });

        datasets.push({
            label: day.label + ' (forecast)',
            data: data,
            borderColor: colors[idx],
            backgroundColor: colors[idx] + '20',
            borderWidth: 3,
            tension: 0.4,
            pointRadius: 2,
            fill: idx === 0
        });

        // Historical average for comparison (first year in data)
        if (day.historical.length > 0) {
            var histData = day.historical[0].hourly.map(function(h) {
                return type === 'temp' ? h.temp : h.hum;
            });
            datasets.push({
                label: day.historical[0].year + ' actual',
                data: histData,
                borderColor: colors[idx] + '60',
                borderWidth: 1,
                borderDash: [5, 5],
                tension: 0.4,
                pointRadius: 0,
                fill: false
            });
        }
    });

    forecastChart = new Chart(ctx, {
        type: 'line',
        data: { labels: labels, datasets: datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: '#aab', font: { size: 11 } }
                }
            },
            scales: {
                x: { ticks: { color: '#667' }, grid: { color: '#2a2a4a' } },
                y: {
                    ticks: { color: '#667' },
                    grid: { color: '#2a2a4a' },
                    title: {
                        display: true,
                        text: type === 'temp' ? '°C' : '%',
                        color: '#667'
                    }
                }
            }
        }
    });
}

// ---- Trend chart ----
function renderTrendChart(trend) {
    var wrap = document.getElementById('trendChartWrap');
    var msg = wrap.querySelector('.loading-msg');
    if (msg) msg.remove();
    var ctx = document.getElementById('trendChart').getContext('2d');
    var labels = trend.recent_days.map(function(d) { return d.date; });
    var temps  = trend.recent_days.map(function(d) { return d.temp; });
    var hums   = trend.recent_days.map(function(d) { return d.hum; });

    trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Temperature °C',
                    data: temps,
                    borderColor: '#ffa502',
                    backgroundColor: '#ffa50220',
                    tension: 0.3,
                    yAxisID: 'y',
                    fill: true
                },
                {
                    label: 'Humidity %',
                    data: hums,
                    borderColor: '#45aaf2',
                    backgroundColor: '#45aaf220',
                    tension: 0.3,
                    yAxisID: 'y1',
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { color: '#aab', font: { size: 11 } } },
                title: {
                    display: true,
                    text: 'Trend: ' + (trend.temp_per_day >= 0 ? '+' : '') +
                          trend.temp_per_day + '°C/day, ' +
                          (trend.hum_per_day >= 0 ? '+' : '') +
                          trend.hum_per_day + '%/day',
                    color: '#aab',
                    font: { size: 12 }
                }
            },
            scales: {
                x: { ticks: { color: '#667' }, grid: { color: '#2a2a4a' } },
                y: {
                    type: 'linear', position: 'left',
                    ticks: { color: '#ffa502' }, grid: { color: '#2a2a4a' },
                    title: { display: true, text: '°C', color: '#ffa502' }
                },
                y1: {
                    type: 'linear', position: 'right',
                    ticks: { color: '#45aaf2' }, grid: { drawOnChartArea: false },
                    title: { display: true, text: '%', color: '#45aaf2' }
                }
            }
        }
    });
}

// ---- Day selector ----
function renderDaySelector(days) {
    var container = document.getElementById('daySelector');
    var html = '';
    days.forEach(function(day, idx) {
        html += '<button class="day-btn' + (idx === 0 ? ' active' : '') + '" ' +
                'onclick="selectDay(' + idx + ')">' +
                day.label + ' (' + day.date + ')</button>';
    });
    container.innerHTML = html;
}

function selectDay(idx) {
    selectedDay = idx;
    document.querySelectorAll('.day-btn').forEach(function(b, i) {
        b.classList.toggle('active', i === idx);
    });
    renderHourlyTable(forecastData.days, idx);
}

// ---- Hourly table ----
function renderHourlyTable(days, dayIdx) {
    var day = days[dayIdx];
    var container = document.getElementById('hourlyTable');

    var html = '<table><tr><th>Hour</th><th>Forecast °C</th><th>Forecast %</th>';
    day.historical.forEach(function(yearData) {
        html += '<th>' + yearData.year + ' °C</th><th>' + yearData.year + ' %</th>';
    });
    html += '</tr>';

    for (var h = 0; h < 24; h++) {
        var fc = day.forecast.hourly[h];
        html += '<tr>';
        html += '<td>' + h + ':00</td>';

        // Forecast cells
        var fcTemp = fc.temp !== null ? fc.temp.toFixed(1) : '';
        var fcHum  = fc.hum  !== null ? fc.hum.toFixed(0)  : '';
        html += '<td class="forecast-row" style="background:' + tempColor(fc.temp) + '">' + fcTemp + '</td>';
        html += '<td class="forecast-row" style="background:' + humColor(fc.hum) + '">' + fcHum + '</td>';

        // Historical cells
        day.historical.forEach(function(yearData) {
            var ht = yearData.hourly[h];
            var tVal = ht.temp !== null ? ht.temp.toFixed(1) : '';
            var hVal = ht.hum  !== null ? ht.hum.toFixed(0)  : '';
            html += '<td style="background:' + tempColor(ht.temp) + '">' + tVal + '</td>';
            html += '<td style="background:' + humColor(ht.hum) + '">' + hVal + '</td>';
        });

        html += '</tr>';
    }

    html += '</table>';
    container.innerHTML = html;
}

// ---- Tab switching ----
document.querySelectorAll('.tab-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        selectedType = this.dataset.type;
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        this.classList.add('active');
        renderForecastChart(forecastData.days, selectedType);
    });
});
