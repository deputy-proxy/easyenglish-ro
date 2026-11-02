function parsePoints(value) {
    return value.trim().split(/\s+/).map((point) => point.split(',').map(Number));
}

function formatPoints(points) {
    return points.map(([x, y]) => `${x},${y}`).join(' ');
}

function interpolatePoints(from, to, progress) {
    return from.map(([x, y], index) => [
        x + ((to[index][0] - x) * progress),
        y + ((to[index][1] - y) * progress),
    ]);
}

export function initializeProgressAnimation(gsap, ScrollTrigger) {
    const chart = document.querySelector('[data-progress-chart]');

    if (!chart) {
        return () => {};
    }

    const center = [220, 184];
    const grid = chart.querySelector('[data-chart-grid]');
    const labels = chart.querySelector('[data-chart-labels]');
    const shapes = gsap.utils.toArray(chart.querySelectorAll('[data-chart-shape]'));
    const points = gsap.utils.toArray(chart.querySelectorAll('[data-chart-points] circle'));
    const legend = chart.closest('article')?.querySelector('[data-chart-legend]');

    if (!shapes.length) {
        return () => {};
    }

    const shapeData = shapes.map((shape) => {
        const target = parsePoints(shape.getAttribute('points'));

        return {
            shape,
            target,
            collapsed: target.map(() => center),
            fillOpacity: Number(shape.getAttribute('fill-opacity')) || 0,
        };
    });
    const pointData = points.map((point) => ({
        point,
        x: point.getAttribute('cx'),
        y: point.getAttribute('cy'),
        radius: point.getAttribute('r'),
    }));
    const gridLines = gsap.utils.toArray(grid?.querySelectorAll('polygon, path') || []);
    const gridLineStyles = gridLines.map((line) => ({
        line,
        dasharray: line.style.strokeDasharray,
        dashoffset: line.style.strokeDashoffset,
    }));
    const animationContext = gsap.context(() => {}, chart);

    shapeData.forEach(({ shape, collapsed }) => {
        shape.setAttribute('points', formatPoints(collapsed));
        shape.setAttribute('fill-opacity', '0');
    });

    gridLineStyles.forEach(({ line }) => {
        const length = line.getTotalLength?.();

        if (length) {
            line.style.strokeDasharray = String(length);
            line.style.strokeDashoffset = String(length);
        }
    });

    gsap.set([grid, labels].filter(Boolean), { autoAlpha: 0 });

    if (legend) {
        gsap.set(legend, { autoAlpha: 0, y: 8 });
    }

    gsap.set(points, {
        attr: { cx: center[0], cy: center[1], r: 0 },
        autoAlpha: 0,
    });

    let timeline;

    animationContext.add(() => {
        timeline = gsap.timeline({ defaults: { ease: 'power3.out' } });

        if (grid) {
            timeline.to(grid, { autoAlpha: 1, duration: 0.55 }, 0);
        }

        if (gridLines.length) {
            timeline.to(gridLines, {
                strokeDashoffset: 0,
                duration: 0.8,
                stagger: 0.045,
            }, 0);
        }

        if (labels) {
            timeline.to(labels, { autoAlpha: 1, duration: 0.5 }, 0.25);
        }

        shapeData.forEach(({ shape, target, collapsed, fillOpacity }, index) => {
            const state = { progress: 0 };

            timeline.to(state, {
                progress: 1,
                duration: 1.05,
                onUpdate: () => {
                    shape.setAttribute('points', formatPoints(interpolatePoints(collapsed, target, state.progress)));
                    shape.setAttribute('fill-opacity', String(fillOpacity * state.progress));
                },
            }, 0.2 + (index * 0.13));
        });

        if (pointData.length) {
            timeline.to(points, {
                autoAlpha: 1,
                attr: { r: 4 },
                duration: 0.35,
                stagger: 0.045,
            }, 1.05);

            pointData.forEach(({ point, x, y }) => {
                timeline.to(point, {
                    attr: { cx: x, cy: y },
                    duration: 0.9,
                    ease: 'power3.out',
                }, 0.25);
            });
        }

        if (legend) {
            timeline.to(legend, { autoAlpha: 1, y: 0, duration: 0.45 }, 1.25);
        }

        ScrollTrigger.create({
            trigger: chart,
            start: 'top 82%',
            animation: timeline,
            toggleActions: 'play none none reverse',
        });
    });

    return () => {
        animationContext.revert();

        shapeData.forEach(({ shape, target, fillOpacity }) => {
            shape.setAttribute('points', formatPoints(target));
            shape.setAttribute('fill-opacity', String(fillOpacity));
        });

        pointData.forEach(({ point, x, y, radius }) => {
            point.setAttribute('cx', x);
            point.setAttribute('cy', y);
            point.setAttribute('r', radius);
        });

        gridLineStyles.forEach(({ line, dasharray, dashoffset }) => {
            line.style.strokeDasharray = dasharray;
            line.style.strokeDashoffset = dashoffset;
        });

        gsap.set([grid, labels, legend].filter(Boolean), { clearProps: 'all' });
    };
}
