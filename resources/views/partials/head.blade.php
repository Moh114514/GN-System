<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@fluxAppearance

<script>
    window.deferLoadingAlpine = (startAlpine) => {
        window.__gnStartAlpine = startAlpine;
    };
</script>

<script data-navigate-once>
    (() => {
        const applyAppearance = window.Flux?.applyAppearance?.bind(window.Flux);

        if (!applyAppearance) {
            return;
        }

        const syncResolvedAppearance = () => {
            const resolvedAppearance = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';

            document.cookie = `flux_resolved_appearance=${resolvedAppearance}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
        };

        window.Flux.applyAppearance = (appearance) => {
            applyAppearance(appearance);
            syncResolvedAppearance();
        };

        syncResolvedAppearance();
    })();
</script>

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ asset('images/lightyear18-logo-light.png') }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('images/lightyear18-logo-light.png') }}">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
