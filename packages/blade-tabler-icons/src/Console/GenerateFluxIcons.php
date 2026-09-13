<?php

namespace Superbyte\BladeTablerIcons\Console;

use Illuminate\Console\Command;

class GenerateFluxIcons extends Command
{
    protected $signature = 'tabler-icons:flux
        {icons?* : Icon names to generate (kebab-case, e.g. star heart shopping-cart-plus)}
        {--all : Generate every bundled icon instead of listing names}
        {--style=both : Which set(s) to generate: outline, filled, or both}';

    protected $description = 'Generate Flux-UI-compatible Blade icon views from the bundled Tabler icons, so they work with icon="..." props.';

    public function handle(): int
    {
        $requested = $this->argument('icons');
        $styles = match ($this->option('style')) {
            'outline' => ['outline' => 'tabler'],
            'filled' => ['filled' => 'tabler-filled'],
            default => ['outline' => 'tabler', 'filled' => 'tabler-filled'],
        };

        if (empty($requested) && ! $this->option('all')) {
            $this->error('Pass icon names, e.g. "php artisan tabler-icons:flux star heart", or use --all.');

            return self::FAILURE;
        }

        foreach ($styles as $sourceSet => $fluxVendor) {
            $this->generateSet($sourceSet, $fluxVendor, $requested);
        }

        return self::SUCCESS;
    }

    private function generateSet(string $sourceSet, string $fluxVendor, array $requested): void
    {
        $sourceDir = __DIR__.'/../../resources/svg/'.$sourceSet;
        $targetDir = resource_path('views/flux/icon/'.$fluxVendor);

        $names = $this->option('all')
            ? collect(glob($sourceDir.'/*.svg'))->map(fn ($path) => pathinfo($path, PATHINFO_FILENAME))
            : collect($requested);

        if (! is_dir($targetDir)) {
            mkdir($targetDir, recursive: true);
        }

        $generated = 0;
        $missing = [];

        foreach ($names as $name) {
            $source = $sourceDir.'/'.$name.'.svg';

            if (! file_exists($source)) {
                $missing[] = $name;

                continue;
            }

            file_put_contents($targetDir.'/'.$name.'.blade.php', $this->fluxBladeTemplate($source));
            $generated++;
        }

        $relative = str_replace(base_path().'/', '', $targetDir);
        $this->info("{$sourceSet}: generated {$generated} icon(s) into {$relative}");

        if ($missing !== []) {
            $this->warn("{$sourceSet}: not found — ".implode(', ', $missing));
        }
    }

    private function fluxBladeTemplate(string $sourceSvgPath): string
    {
        $svg = file_get_contents($sourceSvgPath);
        preg_match('/<svg[^>]*>(.*)<\/svg>/s', $svg, $matches);
        $inner = trim($matches[1] ?? '');

        return <<<BLADE
        @php \$attributes = \$unescapedForwardedAttributes ?? \$attributes; @endphp
        @php
            \$classes = Flux::classes('shrink-0')->add('[:where(&)]:size-4');
        @endphp
        <svg {{ \$attributes->class(\$classes) }} data-flux-icon aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            {$inner}
        </svg>

        BLADE;
    }
}
