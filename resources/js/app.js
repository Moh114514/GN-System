import * as echarts from 'echarts';
import html2canvas from 'html2canvas';

const chartInstances = new WeakMap();
const chartObservers = new WeakMap();

function renderDashboardCharts() {
    document.querySelectorAll('[data-dashboard-chart]').forEach((element) => {
        const values = JSON.parse(element.dataset.chartValues || '[]');
        const key = element.dataset.dashboardChart;
        chartInstances.get(element)?.dispose();
        chartObservers.get(element)?.disconnect();
        if (values.length === 0) {
            return;
        }
        const chart = echarts.init(element);
        chartInstances.set(element, chart);
        const categories = values.map((row) => row.key);
        const data = values.map((row) => row.value);
        const isPie = ['grade_distribution', 'source_distribution'].includes(key);
        const isLine = ['monthly_promotion', 'monthly_consumption'].includes(key);
        chart.setOption(isPie ? {
            tooltip: { trigger: 'item' },
            legend: { bottom: 0 },
            series: [{
                type: 'pie',
                radius: ['38%', '68%'],
                data: values.map((row) => ({ name: row.key, value: row.value })),
            }],
        } : {
            tooltip: { trigger: 'axis' },
            grid: { left: 56, right: 24, top: 20, bottom: 48 },
            xAxis: { type: 'category', data: categories, axisLabel: { interval: 0, rotate: categories.length > 5 ? 25 : 0 } },
            yAxis: { type: 'value' },
            series: [{
                type: isLine ? 'line' : 'bar',
                data,
                smooth: isLine,
                itemStyle: { color: '#0f766e' },
                areaStyle: isLine ? { color: 'rgba(15, 118, 110, 0.12)' } : undefined,
            }],
        });
        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(element);
        chartObservers.set(element, observer);
    });
}

window.gnExportDashboardPng = async (element) => {
    const canvas = await html2canvas(element, {
        backgroundColor: '#ffffff',
        scale: 2,
        useCORS: false,
    });
    const link = document.createElement('a');
    link.download = `dashboard-${new Date().toISOString().slice(0, 19).replaceAll(':', '-')}.png`;
    link.href = canvas.toDataURL('image/png');
    link.click();
};

document.addEventListener('DOMContentLoaded', renderDashboardCharts);
document.addEventListener('livewire:navigated', renderDashboardCharts);
document.addEventListener('dashboard-updated', () => window.setTimeout(renderDashboardCharts));
