const TRANSITION_DISTANCE = 140;
const MAX_RADIUS = 24;

export function initializeHeroShellNavigation() {
    const shell = document.querySelector('[data-hero-shell]');
    const header = shell?.querySelector('[data-public-site-header]');

    if (!shell || !header) {
        return () => {};
    }

    const controller = new AbortController();
    const options = { signal: controller.signal };
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const desktopViewport = window.matchMedia('(min-width: 768px)');
    let frame = null;

    const update = () => {
        frame = null;

        const progress = reducedMotion.matches
            ? (window.scrollY >= TRANSITION_DISTANCE ? 1 : 0)
            : Math.min(1, Math.max(0, window.scrollY / TRANSITION_DISTANCE));
        const viewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const gutter = desktopViewport.matches ? viewportWidth * 0.025 * progress : 0;
        const width = viewportWidth - gutter * 2;
        const radius = MAX_RADIUS * progress;

        shell.style.setProperty('--hero-shell-width', width + 'px');
        shell.style.setProperty('--hero-shell-radius', radius + 'px');
        header.classList.toggle('is-floating', progress >= 1);
    };

    const scheduleUpdate = () => {
        if (frame !== null) {
            return;
        }

        frame = window.requestAnimationFrame(update);
    };

    window.addEventListener('scroll', scheduleUpdate, { passive: true, ...options });
    window.addEventListener('resize', scheduleUpdate, { passive: true, ...options });
    reducedMotion.addEventListener('change', scheduleUpdate, options);
    desktopViewport.addEventListener('change', scheduleUpdate, options);
    window.addEventListener('pagehide', (event) => {
        if (!event.persisted) {
            controller.abort();
            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }
        }
    }, options);

    update();

    return () => {
        controller.abort();
        if (frame !== null) {
            window.cancelAnimationFrame(frame);
        }
        shell.style.removeProperty('--hero-shell-width');
        shell.style.removeProperty('--hero-shell-radius');
        header.classList.remove('is-floating');
    };
}
