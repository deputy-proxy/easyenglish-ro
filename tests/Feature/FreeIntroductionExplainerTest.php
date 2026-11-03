<?php

test('free introduction explainer is localized and appears directly before the weekly calendar', function () {
    $copy = [
        'ro' => [
            'title' => 'Prima conversație. Gratuit. Fără obligații.',
            'description' => 'În 15 minute, discuți cu un profesor despre nivelul tău, obiectivele și situațiile în care ai nevoie de engleză. Vezi cum decurge o conversație ghidată și cum pot fi adaptate sesiunile următoare nevoilor tale.',
            'duration' => '15 min',
            'channel' => 'Pe WhatsApp',
            'teacher' => 'Profesor EasyEnglish',
            'facts_label' => 'Detalii despre sesiunea introductivă',
            'unsupported' => ['evaluare formală', 'raport scris', 'plan de acțiune', 'rezultat garantat'],
        ],
        'en' => [
            'title' => 'Your first conversation. Free. No obligation.',
            'description' => 'In 15 minutes, talk with a teacher about your level, goals and the situations where you need English. Experience a guided conversation and see how future sessions can be tailored to your needs.',
            'duration' => '15 minutes',
            'channel' => 'On WhatsApp',
            'teacher' => 'EasyEnglish teacher',
            'facts_label' => 'Free introduction details',
            'unsupported' => ['formal assessment', 'written report', 'action plan', 'guaranteed result'],
        ],
    ];

    foreach ($copy as $locale => $expected) {
        $response = $this->withSession(['locale' => $locale])->get(route('home'));
        $content = $response->getContent();

        $response->assertOk()
            ->assertSee($expected['title'])
            ->assertSee($expected['description'])
            ->assertSee($expected['duration'])
            ->assertSee($expected['channel'])
            ->assertSee($expected['teacher'])
            ->assertSee($expected['facts_label']);

        expect(preg_match('/<section[^>]*id="availability"[^>]*>(.*?)<\/section>/s', $content, $section))->toBe(1)
            ->and(preg_match('/<aside[^>]*id="free-intro-explainer"[^>]*>(.*?)<\/aside>/s', $section[1], $aside))->toBe(1);

        $asideHtml = $aside[0];
        $asideText = html_entity_decode(strip_tags($asideHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $asidePosition = strpos($section[1], 'id="free-intro-explainer"');
        $calendarPosition = strpos($section[1], 'data-availability');

        expect($asidePosition)->toBeInt()
            ->and($calendarPosition)->toBeInt()
            ->and($asidePosition)->toBeLessThan($calendarPosition)
            ->and($asideHtml)->toContain('data-reveal');

        foreach ($expected['unsupported'] as $claim) {
            expect(mb_stripos($asideText, $claim))->toBeFalse();
        }
    }
});
