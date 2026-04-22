<?php

namespace Bardh78\LaravelWisra\Tests;

use Bardh78\LaravelWisra\Tests\Fixtures\TestableLaravelWisraServiceProvider;
use Illuminate\Http\Request;

class LivewireCompatibilityTest extends TestCase
{
    private TestableLaravelWisraServiceProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new TestableLaravelWisraServiceProvider($this->app);
    }

    public function test_it_instruments_standard_blade_views(): void
    {
        $path = '/project/resources/views/welcome.blade.php';
        $blade = "<div>\n    <span>Hello</span>\n</div>";

        $compiled = $this->provider->instrument($blade, $path);

        $this->assertStringContainsString("<!-- [view] {$path} -->", $compiled);
        $this->assertStringContainsString('wisra-start-line="1"', $compiled);
        $this->assertStringContainsString('wisra-end-line="3"', $compiled);
    }

    public function test_it_skips_views_in_the_default_livewire_directory(): void
    {
        $path = '/project/resources/views/livewire/counter.blade.php';
        $blade = "<div>\n    <span>Hello</span>\n</div>";

        $compiled = $this->provider->instrument($blade, $path);

        $this->assertSame($blade, $compiled);
    }

    public function test_it_skips_views_containing_livewire_syntax(): void
    {
        $path = '/project/resources/views/dashboard.blade.php';
        $blade = <<<'BLADE'
<div>
    @livewire('counter')
    <button wire:click="increment">+</button>
</div>
BLADE;

        $compiled = $this->provider->instrument($blade, $path);

        $this->assertSame($blade, $compiled);
    }

    public function test_it_skips_livewire_update_requests(): void
    {
        $request = Request::create('/livewire/update', 'POST', server: [
            'HTTP_X_LIVEWIRE' => 'true',
        ]);

        $this->app->instance('request', $request);

        $this->assertTrue($this->provider->livewireRequest());
        $this->assertTrue($this->provider->skipBladeInstrumentation(
            '/project/resources/views/dashboard.blade.php',
            '<div>Hello</div>',
        ));
    }
}
