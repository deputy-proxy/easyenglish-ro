<?php

test('availability section no longer renders the introductory-session feature box in either locale', function () {
    foreach (['ro', 'en'] as $locale) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertDontSee('free-intro-explainer')
            ->assertDontSee('Prima conversație. Gratuit. Fără obligații.')
            ->assertDontSee('Your first conversation. Free. No obligation.')
            ->assertDontSee('free_intro_facts_label');

        expect(preg_match('/<section[^>]*id="availability"[^>]*>(.*?)<\/section>/s', $content, $section))->toBe(1)
            ->and($section[1])->toContain('data-availability')
            ->and($section[1])->toContain('data-weekgrid')
            ->and($section[1])->toContain('data-weekgrid-mobile');
    }
});
