<?php

namespace Bardh78\LaravelWisra\Tests;

use Bardh78\LaravelWisra\Tests\Fixtures\TestableLaravelWisraServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

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

    public function test_it_injects_runtime_meta_tags_into_document_head(): void
    {
        $request = Request::create('/app/panel', 'GET');
        $route = new Route(['GET'], '/app/panel', [
            'as' => 'panel.index',
            'uses' => 'App\\Http\\Controllers\\PanelController@index',
            'controller' => 'App\\Http\\Controllers\\PanelController@index',
        ]);

        $request->setRouteResolver(static fn () => $route);

        $this->app->instance('request', $request);

        $compiled = $this->provider->instrument(
            "<html>\n<head>\n    <title>Panel</title>\n</head>\n<body></body>\n</html>",
            '/project/resources/views/layouts/app.blade.php',
        );

        $this->assertStringContainsString(
            '<?php echo \\Bardh78\\LaravelWisra\\LaravelWisraServiceProvider::renderCurrentRequestMetaTags(); ?>',
            $compiled,
        );

        $metaTags = \Bardh78\LaravelWisra\LaravelWisraServiceProvider::renderCurrentRequestMetaTags();

        $this->assertStringContainsString('<meta name="wisra-current-route" content="/app/panel">', $metaTags);
        $this->assertStringContainsString('<meta name="wisra-current-route-name" content="panel.index">', $metaTags);
        $this->assertStringContainsString('<meta name="wisra-current-controller" content="App\Http\Controllers\PanelController">', $metaTags);
        $this->assertStringContainsString('<meta name="wisra-current-action" content="index">', $metaTags);
    }
}
