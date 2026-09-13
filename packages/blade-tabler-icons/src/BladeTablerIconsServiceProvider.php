<?php

namespace Superbyte\BladeTablerIcons;

use BladeUI\Icons\Factory;
use Illuminate\Support\ServiceProvider;

class BladeTablerIconsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blade-tabler-icons.php', 'blade-tabler-icons');

        $this->callAfterResolving(Factory::class, function (Factory $factory) {
            $config = $this->app['config']['blade-tabler-icons'];

            // Outline set (Tabler's default style) — <x-tabler-{icon} />
            //
            // The `prefixed` path holds copies of the filled icons renamed to
            // `filled-{icon}.svg`. It must be registered on THIS set (not a
            // `tabler-filled` set): blade-icons resolves names by splitting on
            // the first dash, so a `tabler-filled` prefix can never match and
            // `<x-tabler-filled-{icon} />` would 404. With the copies here,
            // `tabler-filled-heart` resolves as set `tabler` + icon
            // `filled-heart`, which the mirror file satisfies. The mirror is
            // rebuilt by bin/update-icons.sh — never edit it by hand.
            $factory->add('tabler', [
                'paths' => [
                    __DIR__.'/../resources/svg/outline',
                    __DIR__.'/../resources/svg/prefixed',
                ],
                'prefix' => 'tabler',
                'class' => $config['class'] ?? null,
                'attributes' => $config['attributes'] ?? [],
            ]);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/blade-tabler-icons.php' => config_path('blade-tabler-icons.php'),
            ], 'blade-tabler-icons-config');

            $this->commands([
                Console\GenerateFluxIcons::class,
            ]);
        }
    }
}
