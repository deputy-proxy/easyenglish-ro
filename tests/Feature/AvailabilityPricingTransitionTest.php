<?php

test('availability-to-pricing transition is localized, optional, and stays between the existing sections', function () {
    $copy = [
        'ro' => [
            'transition' => 'Sesiunea introductivă este gratuită și nu face parte din niciun pachet. După conversație, poți decide dacă vrei să continui cu un pachet.',
            'optional' => 'poți decide dacă vrei să continui',
            'unsupported' => ['rezervat', 'garantat', 'obligatoriu'],
        ],
        'en' => [
            'transition' => 'Your introductory session is free and independent of any bundle. After the conversation, you can decide whether a bundle is right for you.',
            'optional' => 'you can decide whether a bundle is right for you',
            'unsupported' => ['reserved', 'guaranteed', 'required'],
        ],
    ];

    foreach ($copy as $locale => $expected) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));
        $content = $response->getContent();

        $response->assertOk()->assertSee($expected['transition']);

        $availabilityPosition = strpos($content, 'id="availability"');
        $transitionPosition = strpos($content, 'id="availability-pricing-transition"');
        $bundlesPosition = strpos($content, 'id="bundles"');

        expect($availabilityPosition)->toBeInt()
            ->and($transitionPosition)->toBeInt()
            ->and($bundlesPosition)->toBeInt()
            ->and($availabilityPosition)->toBeLessThan($transitionPosition)
            ->and($transitionPosition)->toBeLessThan($bundlesPosition);

        expect(preg_match('/<div[^>]*id="availability-pricing-transition"[^>]*>(.*?)<\/div>/s', $content, $matches))->toBe(1);

        $transitionHtml = $matches[0];
        $transitionText = html_entity_decode(strip_tags($transitionHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        expect($transitionHtml)->toContain('data-reveal')
            ->and($transitionHtml)->not->toContain('<a')
            ->and($transitionHtml)->not->toContain('<button')
            ->and($transitionText)->toContain($expected['optional']);

        foreach ($expected['unsupported'] as $claim) {
            expect(mb_stripos($transitionText, $claim))->toBeFalse();
        }
    }
});
