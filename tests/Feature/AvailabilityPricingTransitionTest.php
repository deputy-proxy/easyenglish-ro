<?php

test('availability section no longer renders the introductory-session pricing tagline in either locale', function () {
    foreach (['ro', 'en'] as $locale) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertDontSee('availability-pricing-transition')
            ->assertDontSee('Sesiunea introductivă este gratuită și nu face parte din niciun pachet.')
            ->assertDontSee('Your introductory session is free and independent of any bundle.');

        $availabilityPosition = strpos($content, 'id="availability"');
        $bundlesPosition = strpos($content, 'id="bundles"');

        expect($availabilityPosition)->toBeInt()
            ->and($bundlesPosition)->toBeInt()
            ->and($availabilityPosition)->toBeLessThan($bundlesPosition);
    }
});
