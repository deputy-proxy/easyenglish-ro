import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const templates = [
    'resources/views/components/booking/availability-section.blade.php',
    'resources/views/public/partials/booking-authenticated-form.blade.php',
    'resources/views/public/partials/booking-bundle-form.blade.php',
    'resources/views/public/purchase.blade.php',
];

test('phone input patterns are valid HTML Unicode-set regular expressions and accept supported formats', () => {
    const patterns = templates.map((path) => {
        const source = readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8');
        const phoneInput = source.split('\n').find((line) => line.includes('name="phone"') && line.includes('pattern='));
        assert.ok(phoneInput, `Expected a phone input pattern in ${path}`);
        return phoneInput.match(/pattern="([^"]+)"/)?.[1];
    });

    assert.ok(patterns.every(Boolean));
    assert.equal(new Set(patterns).size, 1, 'All public phone inputs should share the same pattern');

    const pattern = new RegExp(`^(?:${patterns[0]})$`, 'v');
    for (const phone of ['+40728008133', '+40 728 008 133', '0728-008-133', '(0728) 008.133']) {
        assert.equal(pattern.test(phone), true, `Expected ${phone} to be accepted`);
    }
    for (const phone of ['abc1234567', '+40/728008133', '123']) {
        assert.equal(pattern.test(phone), false, `Expected ${phone} to be rejected`);
    }
});
