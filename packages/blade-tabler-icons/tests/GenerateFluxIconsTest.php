<?php

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(resource_path('views/flux'));
});

it('generates flux-compatible icon views for requested icons', function () {
    $this->artisan('tabler-icons:flux', ['icons' => ['star', 'heart']])
        ->assertSuccessful();

    expect(resource_path('views/flux/icon/tabler/star.blade.php'))->toBeFile()
        ->and(resource_path('views/flux/icon/tabler/heart.blade.php'))->toBeFile()
        ->and(resource_path('views/flux/icon/tabler-filled/star.blade.php'))->toBeFile();

    $contents = file_get_contents(resource_path('views/flux/icon/tabler/star.blade.php'));
    expect($contents)
        ->toContain('<svg')
        ->toContain('data-flux-icon')
        ->toContain('$attributes->class($classes)');
});

it('warns about icons that do not exist without failing', function () {
    $this->artisan('tabler-icons:flux', ['icons' => ['this-icon-does-not-exist']])
        ->assertSuccessful();
});
