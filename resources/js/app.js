import * as echarts from 'echarts';
import html2canvas from 'html2canvas';

const chartInstances = new WeakMap();
const chartObservers = new WeakMap();

function dashboardColors(element) {
    const styles = window.getComputedStyle(element);
    const rootStyles = window.getComputedStyle(document.documentElement);

    return {
        primary: rootStyles.getPropertyValue('--crm-primary').trim() || '#14b8a6',
        blue: rootStyles.getPropertyValue('--crm-blue').trim() || '#3b82f6',
        ink: rootStyles.getPropertyValue('--crm-ink').trim() || '#1f2937',
        muted: rootStyles.getPropertyValue('--crm-muted').trim() || '#6b7280',
        line: rootStyles.getPropertyValue('--crm-line').trim() || '#e5e7eb',
        surface: styles.backgroundColor,
    };
}

function renderDashboardCharts() {
    document.querySelectorAll('[data-dashboard-chart]').forEach((element) => {
        const values = JSON.parse(element.dataset.chartValues || '[]');
        const key = element.dataset.dashboardChart;
        const colors = dashboardColors(element);
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
        const isRate = ['repurchase_rate', 'followup_completion_rate'].includes(key);
        const isRevenueOrders = key === 'monthly_revenue_orders';
        const palette = [colors.primary, colors.blue, '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'];
        chart.setOption(isRevenueOrders ? {
            tooltip: { trigger: 'axis' },
            grid: { left: 68, right: 48, top: 22, bottom: 42 },
            xAxis: {
                type: 'category',
                data: categories,
                axisLine: { lineStyle: { color: colors.line } },
                axisLabel: { color: colors.muted },
            },
            yAxis: [{
                type: 'value',
                splitLine: { lineStyle: { color: colors.line, type: 'dashed' } },
                axisLabel: {
                    color: colors.muted,
                    formatter: (value) => Number(value).toLocaleString(),
                },
            }, {
                type: 'value',
                splitLine: { show: false },
                axisLabel: { color: colors.muted },
                minInterval: 1,
            }],
            series: [{
                name: '营收（KRW）',
                type: 'bar',
                data,
                barMaxWidth: 34,
                itemStyle: { color: colors.primary, borderRadius: [5, 5, 0, 0] },
            }, {
                name: '订单数（单）',
                type: 'line',
                yAxisIndex: 1,
                data: values.map((row) => row.orders),
                smooth: true,
                symbolSize: 7,
                itemStyle: { color: colors.blue },
                lineStyle: { width: 3, color: colors.blue },
            }],
        } : isPie ? {
            color: palette,
            tooltip: { trigger: 'item', valueFormatter: (value) => Number(value).toLocaleString() },
            legend: { bottom: 0, textStyle: { color: colors.muted } },
            series: [{
                type: 'pie',
                radius: ['42%', '70%'],
                center: ['50%', '44%'],
                label: { color: colors.ink, formatter: '{b}\n{d}%' },
                data: values.map((row) => ({ name: row.key, value: row.value })),
            }],
        } : isRate ? {
            series: [{
                type: 'gauge',
                startAngle: 210,
                endAngle: -30,
                min: 0,
                max: 100,
                progress: { show: true, width: 14, itemStyle: { color: colors.primary } },
                axisLine: { lineStyle: { width: 14, color: [[1, colors.line]] } },
                pointer: { show: false },
                axisTick: { show: false },
                splitLine: { show: false },
                axisLabel: { show: false },
                detail: {
                    valueAnimation: true,
                    formatter: '{value}%',
                    color: colors.ink,
                    fontSize: 28,
                    offsetCenter: [0, '8%'],
                },
                data: [{ value: Number(Number(data[0] || 0).toFixed(2)) }],
            }],
        } : {
            tooltip: { trigger: 'axis' },
            grid: { left: 58, right: 18, top: 20, bottom: 48 },
            xAxis: {
                type: 'category',
                data: categories,
                axisLine: { lineStyle: { color: colors.line } },
                axisLabel: { color: colors.muted, interval: 0, rotate: categories.length > 5 ? 25 : 0 },
            },
            yAxis: {
                type: 'value',
                splitLine: { lineStyle: { color: colors.line, type: 'dashed' } },
                axisLabel: { color: colors.muted },
            },
            series: [{
                type: isLine ? 'line' : 'bar',
                data,
                smooth: isLine,
                symbolSize: 7,
                barMaxWidth: 34,
                itemStyle: { color: colors.primary, borderRadius: isLine ? 0 : [5, 5, 0, 0] },
                lineStyle: { width: 3, color: colors.primary },
                areaStyle: isLine ? { color: 'rgba(20, 184, 166, 0.14)' } : undefined,
            }],
        });
        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(element);
        chartObservers.set(element, observer);
    });
}

function scheduleDashboardCharts() {
    window.requestAnimationFrame(() => window.requestAnimationFrame(renderDashboardCharts));
}

window.gnExportDashboardPng = async () => {
    const element = document.querySelector('[data-dashboard-export]');
    if (! (element instanceof HTMLElement)) {
        throw new Error('没有找到可导出的看板内容，请刷新页面后重试。');
    }

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

document.addEventListener('DOMContentLoaded', scheduleDashboardCharts);
document.addEventListener('livewire:navigated', scheduleDashboardCharts);
document.addEventListener('dashboard-updated', scheduleDashboardCharts);
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morph.updated', scheduleDashboardCharts);
});
