<x-layouts::app.sidebar :title="$title ?? null">
    <main class="crm-content">
        {{ $slot }}
    </main>
</x-layouts::app.sidebar>
