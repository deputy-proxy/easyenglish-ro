import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const template = readFileSync(new URL('../../resources/views/components/booking/availability-section.blade.php', import.meta.url), 'utf8');

test('availability requests use the current page origin instead of the configured absolute application URL', () => {
    assert.match(template, /new URL\('\/availability', window\.location\.origin\)/);
    assert.match(template, /availabilityUrl\.searchParams\.set\('week', week\)/);
    assert.doesNotMatch(template, /fetch\('\{\{ route\('availability'\) \}\}/);
});

test('availability request failures retain safe user messaging and expose actionable browser diagnostics', () => {
    assert.match(template, /Availability request failed with HTTP \$\{response\.status\}/);
    assert.match(template, /console\.error\('\[EasyEnglish\] Availability calendar request failed\.'/);
    assert.match(template, /status\.textContent = loadError/);
});

test('availability rendering uses its days argument when reporting rendered slots', () => {
    assert.match(template, /const render = days => \{[\s\S]*availableSlotsCount: days\.reduce\(/);
    assert.doesNotMatch(template, /availableSlotsCount: data\.days\.reduce\(/);
});
