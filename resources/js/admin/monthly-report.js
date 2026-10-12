/**
 * Monthly report: bar chart of what was received each day. Figures are formatted by the server;
 * the chart only plots the plain numbers it is given.
 */
import ApexCharts from 'apexcharts';

document.addEventListener('DOMContentLoaded', () => {
    const holder = document.getElementById('daily-chart');
    const data = document.getElementById('daily-chart-data');

    if (!holder || !data) {
        return;
    }

    const { series, categories, labels } = JSON.parse(data.textContent ?? '{}');

    new ApexCharts(holder, {
        chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [{ name: labels.series, data: series }],
        xaxis: { categories },
        dataLabels: { enabled: false },
        yaxis: { labels: { formatter: (value) => new Intl.NumberFormat(document.documentElement.lang).format(value) } },
        tooltip: { y: { formatter: (value) => new Intl.NumberFormat(document.documentElement.lang, { minimumFractionDigits: 2 }).format(value) } },
    }).render();
});
