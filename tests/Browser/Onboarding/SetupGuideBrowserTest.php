<?php

/** @noinspection StaticClosureCanBeUsedInspection */

declare(strict_types=1);

test('at 375px the setup guide does not scroll sideways', function () {
    $page = visit('/setup-guide');
    $page->resize(375, 812);

    $page->assertSee('Before you start')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});
