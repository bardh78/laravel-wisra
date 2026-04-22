<?php

namespace Bardh78\LaravelWisra\Tests\Fixtures;

use Bardh78\LaravelWisra\LaravelWisraServiceProvider;

class TestableLaravelWisraServiceProvider extends LaravelWisraServiceProvider
{
    public function instrument(string $value, string $path): string
    {
        return $this->instrumentBladeTemplate($value, $path);
    }

    public function skipBladeInstrumentation(string $path, string $value = ''): bool
    {
        return $this->shouldSkipBladeInstrumentation($path, $value);
    }

    public function livewireRequest(): bool
    {
        return $this->isLivewireRequest();
    }
}
