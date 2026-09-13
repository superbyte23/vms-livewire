<?php

use Illuminate\Support\Facades\Blade;

it('renders an outline icon component', function () {
    $html = Blade::render('<x-tabler-heart class="w-6 h-6" />');

    expect($html)
        ->toContain('<svg')
        ->toContain('class="w-6 h-6"');
});

it('renders a filled icon component', function () {
    $html = Blade::render('<x-tabler-filled-heart />');

    expect($html)->toContain('<svg');
});

it('renders via the @svg directive', function () {
    $html = Blade::render("@svg('tabler-star', 'w-4 h-4')");

    expect($html)
        ->toContain('<svg')
        ->toContain('class="w-4 h-4"');
});

it('ships the full outline and filled sets', function () {
    $outline = glob(__DIR__.'/../resources/svg/outline/*.svg');
    $filled = glob(__DIR__.'/../resources/svg/filled/*.svg');

    expect(count($outline))->toBeGreaterThan(5000)
        ->and(count($filled))->toBeGreaterThan(1000);
});
