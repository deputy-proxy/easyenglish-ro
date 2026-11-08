import assert from 'node:assert/strict';
import test from 'node:test';
import { initializePainPointStack } from '../../resources/js/animations/pain-points.js';

function createFixture(isMobile = true) {
    const triggers = [];
    const animations = [];
    const listeners = new Map();
    let reverted = false;
    const view = {
        matchMedia: (query) => ({ matches: isMobile && query.includes('max-width') }),
        getComputedStyle: (element) => ({
            getPropertyValue: (property) => {
                if (property === '--pain-point-stack-top') return isMobile ? '24px' : '80px';
                return element.style.getPropertyValue(property);
            },
        }),
        addEventListener: (name, callback) => listeners.set(name, callback),
        removeEventListener: (name) => listeners.delete(name),
    };
    const cards = [80, 120, 100, 90, 110, 70, 95, 85, 105, 80].map((height, index) => {
        const title = { id: `title-${index}`, offsetHeight: height, offsetTop: 70 - index * 20 };
        const description = { id: `description-${index}`, offsetHeight: 40 };
        const content = { id: `content-${index}`, offsetHeight: height, offsetTop: 70 - index * 20 };
        const properties = new Map();

        return {
            style: {
                setProperty: (name, value) => properties.set(name, value),
                getPropertyValue: (name) => properties.get(name) ?? '',
                removeProperty: (name) => properties.delete(name),
            },
            querySelector: (selector) => selector === '[data-pain-point-content]' ? content : (selector === '[data-pain-point-title]' ? title : (selector === '[data-pain-point-description]' ? description : null)),
            classList: {
                toggle: (name, force) => force ? properties.set(name, true) : properties.delete(name),
                remove: (name) => properties.delete(name),
                contains: (name) => properties.has(name),
            },
            title,
            description,
            content,
        };
    });
    const classes = new Set();
    const section = {
        ownerDocument: { defaultView: view, fonts: { ready: Promise.resolve() } },
        classList: {
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name),
            contains: (name) => classes.has(name),
        },
        querySelectorAll: () => cards,
        style: {
            getPropertyValue: (property) => property === '--pain-point-stack-top' ? (isMobile ? '24px' : '80px') : '',
        },
    };
    const root = { querySelector: () => section };
    const contexts = [];
    const gsap = {
        context: (callback) => {
            callback();
            const context = { revert: () => { reverted = true; } };
            contexts.push(context);
            return context;
        },
        fromTo: (target, from, to) => {
            const animation = { target, from, to };
            animations.push(animation);
            return animation;
        },
    };
    const ScrollTrigger = {
        create: (configuration) => {
            triggers.push(configuration);
            return {};
        },
        refresh: () => {},
    };

    return {
        animations,
        cards,
        classes,
        contexts,
        gsap,
        listeners,
        reverted: () => reverted,
        root,
        ScrollTrigger,
        section,
        triggers,
    };
}

test('fully overlaps every card at the same sticky position so each new card covers the previous one', () => {
    const fixture = createFixture();
    const cleanup = initializePainPointStack(fixture.gsap, fixture.ScrollTrigger, fixture.root);

    assert.equal(fixture.animations.length, 0);
    assert.equal(fixture.triggers.length, 9);
    assert.equal(fixture.triggers[0].trigger, fixture.cards[1]);
    assert.equal(fixture.triggers[1].trigger, fixture.cards[2]);
    assert.equal(fixture.triggers[0].start, 'top 76%');
    assert.equal(fixture.triggers[0].end(), 'top 24px');
    assert.equal(fixture.triggers[1].end(), 'top 24px');
    assert.equal(fixture.triggers[0].invalidateOnRefresh, true);
    assert.equal(fixture.cards.every((card) => card.style.getPropertyValue('--stack-offset') === '0px'), true);
    assert.equal(fixture.cards[9].style.getPropertyValue('--stack-order'), '10');
    assert.equal(fixture.cards[0].classList.contains('has-stack-shadow'), true);
    assert.equal(fixture.cards[1].classList.contains('has-stack-shadow'), false);
    fixture.triggers[0].onEnter();
    assert.equal(fixture.cards[0].classList.contains('is-compact'), false);
    assert.equal(fixture.cards[1].classList.contains('has-stack-shadow'), true);
    fixture.triggers[0].onLeaveBack();
    assert.equal(fixture.cards[9].style.getPropertyValue('--stack-order'), '10');
    assert.equal(fixture.cards[0].classList.contains('has-stack-shadow'), true);
    assert.equal(fixture.section.classList.contains('is-stacking-enabled'), true);

    cleanup();

    assert.equal(fixture.reverted(), true);
    assert.equal(fixture.section.classList.contains('is-stacking-enabled'), false);
    assert.equal(fixture.cards[1].style.getPropertyValue('--stack-offset'), '');
    assert.equal(fixture.cards[0].classList.contains('has-stack-shadow'), false);
    assert.equal(fixture.listeners.has('resize'), false);
});

test('uses the desktop sticky header offset with the same full-overlap position', () => {
    const fixture = createFixture(false);
    const cleanup = initializePainPointStack(fixture.gsap, fixture.ScrollTrigger, fixture.root);

    assert.equal(fixture.triggers[0].end(), 'top 80px');
    assert.equal(fixture.triggers[1].end(), 'top 80px');

    cleanup();
});

test('leaves the section in its static layout when fewer than two cards exist', () => {
    const fixture = createFixture();
    fixture.section.querySelectorAll = () => fixture.cards.slice(0, 1);

    const cleanup = initializePainPointStack(fixture.gsap, fixture.ScrollTrigger, fixture.root);

    assert.equal(fixture.triggers.length, 0);
    assert.equal(fixture.section.classList.contains('is-stacking-enabled'), false);

    cleanup();

    assert.equal(fixture.reverted(), false);
});
