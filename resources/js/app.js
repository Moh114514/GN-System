import * as echarts from 'echarts';

const localizedDatePicker = ({ value = '', locale = 'zh_CN' } = {}) => ({
    open: false,
    iso: '',
    viewDate: new Date(),
    locale,
    labels: {
        ...(locale === 'ko_KR' ? {
            placeholder: '날짜 선택',
            clear: '지우기',
            today: '오늘',
            previousMonth: '이전 달',
            nextMonth: '다음 달',
        } : {
            placeholder: '选择日期',
            clear: '清除',
            today: '今天',
            previousMonth: '上个月',
            nextMonth: '下个月',
        }),
    },
    init() {
        this.iso = this.normalize(value) || this.normalize(this.$refs.value?.value || '');
        this.viewDate = this.parse(this.iso) || new Date();
        this.syncFromInput();
        this.observer = new MutationObserver(() => this.syncFromInput());
        this.observer.observe(this.$refs.value, { attributes: true, attributeFilter: ['value'] });
    },
    get intlLocale() {
        return this.locale === 'ko_KR' ? 'ko-KR' : 'zh-CN';
    },
    get displayValue() {
        const date = this.parse(this.iso);
        return date ? new Intl.DateTimeFormat(this.intlLocale, { dateStyle: 'medium' }).format(date) : '';
    },
    get monthLabel() {
        return new Intl.DateTimeFormat(this.intlLocale, { year: 'numeric', month: 'long' }).format(this.viewDate);
    },
    get weekdays() {
        const formatter = new Intl.DateTimeFormat(this.intlLocale, { weekday: 'short' });
        return Array.from({ length: 7 }, (_, index) => formatter.format(new Date(2024, 0, 7 + index)));
    },
    get calendarDays() {
        const year = this.viewDate.getFullYear();
        const month = this.viewDate.getMonth();
        const firstDay = new Date(year, month, 1);
        const startOffset = firstDay.getDay();
        const first = new Date(year, month, 1 - startOffset);

        return Array.from({ length: 42 }, (_, index) => {
            const date = new Date(first.getFullYear(), first.getMonth(), first.getDate() + index);
            return {
                iso: this.format(date),
                day: date.getDate(),
                currentMonth: date.getMonth() === month,
                selected: this.format(date) === this.iso,
            };
        });
    },
    normalize(value) {
        return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) && this.parse(value) ? value : '';
    },
    parse(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return null;
        }
        const [year, month, day] = value.split('-').map(Number);
        const date = new Date(year, month - 1, day);
        return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day ? date : null;
    },
    format(date) {
        return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
    },
    syncFromInput() {
        const next = this.normalize(this.$refs.value?.value || '');
        if (next !== this.iso) {
            this.iso = next;
            this.viewDate = this.parse(next) || this.viewDate;
        }
    },
    toggle() {
        this.open = !this.open;
        if (this.open) {
            this.viewDate = this.parse(this.iso) || new Date();
        }
    },
    close() {
        this.open = false;
    },
    goMonth(offset) {
        this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + offset, 1);
    },
    selectDay(value) {
        this.setValue(value);
    },
    today() {
        this.setValue(this.format(new Date()));
    },
    clear() {
        this.setValue('');
    },
    setValue(value) {
        const next = this.normalize(value);
        this.iso = next;
        this.viewDate = this.parse(next) || this.viewDate;
        this.$refs.value.value = next;
        this.$refs.value.dispatchEvent(new Event('input', { bubbles: true }));
        this.$refs.value.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
    },
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('localizedDatePicker', localizedDatePicker);
});

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
        const existingChart = chartInstances.get(element);
        if (values.length === 0) {
            existingChart?.clear();
            return;
        }
        const chart = existingChart ?? echarts.init(element);
        if (!existingChart) {
            chartInstances.set(element, chart);
        }
        const categories = values.map((row) => row.key);
        const data = values.map((row) => row.value);
        const isPie = ['grade_distribution', 'source_distribution'].includes(key);
        const isLine = ['monthly_promotion', 'monthly_consumption'].includes(key);
        const isRate = ['repurchase_rate', 'followup_completion_rate'].includes(key);
        const isRevenueOrders = key === 'monthly_revenue_orders';
        const palette = [colors.primary, colors.blue, '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'];
        const option = isRevenueOrders ? {
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
        };
        chart.setOption({
            animation: true,
            animationDuration: 650,
            animationDurationUpdate: 850,
            animationEasing: 'cubicOut',
            animationEasingUpdate: 'cubicInOut',
            ...option,
        }, { notMerge: true, lazyUpdate: false });
        if (!chartObservers.has(element)) {
            const observer = new ResizeObserver(() => chart.resize());
            observer.observe(element);
            chartObservers.set(element, observer);
        }
    });
}

function scheduleDashboardCharts() {
    window.requestAnimationFrame(() => window.requestAnimationFrame(renderDashboardCharts));
}

let activeBusinessAlertKeys = new Set();

function businessAlertKey(element) {
    return element.dataset.businessAlertKey || element.id;
}

function focusBusinessAlert(element) {
    if (!element) {
        return;
    }
    const rect = element.getBoundingClientRect();
    const inViewport = rect.top >= 0 && rect.bottom <= window.innerHeight;
    if (inViewport) {
        return;
    }
    const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
    element.scrollIntoView({ behavior, block: 'start' });
    const focusTarget = element.querySelector('button, a, input, textarea, select') || element;
    focusTarget.focus({ preventScroll: true });
}

function syncBusinessAlerts() {
    const elements = [...document.querySelectorAll('[data-business-alert]')];
    const nextKeys = new Set();
    elements.forEach((element) => {
        const key = businessAlertKey(element);
        nextKeys.add(key);
        if (!activeBusinessAlertKeys.has(key)) {
            focusBusinessAlert(element);
        }
    });
    activeBusinessAlertKeys = nextKeys;
}

document.addEventListener('DOMContentLoaded', scheduleDashboardCharts);
document.addEventListener('dashboard-updated', scheduleDashboardCharts);
document.addEventListener('livewire:navigated', () => {
    activeBusinessAlertKeys = new Set();
    scheduleDashboardCharts();
    syncBusinessAlerts();
});
window.addEventListener('business-alert-focus', (event) => {
    const element = document.getElementById(event.detail?.alertId || '');
    if (element) {
        focusBusinessAlert(element);
        activeBusinessAlertKeys.add(businessAlertKey(element));
    }
});
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', () => {
        scheduleDashboardCharts();
        syncBusinessAlerts();
    });
});
