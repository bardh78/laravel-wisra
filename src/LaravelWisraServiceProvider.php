<?php

namespace Bardh78\LaravelWisra;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

class LaravelWisraServiceProvider extends ServiceProvider
{
    protected static bool $hasRegisteredBladeInstrumentation = false;

    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-wisra.php', 'laravel-wisra');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laravel-wisra.php' => config_path('laravel-wisra.php'),
            ], 'config');
        }

        $this->injectViewComments();
    }

    /**
     * Wrap each compiled Blade template with HTML comments showing its file path.
     * Only active when enabled and (by default) in the local environment.
     */
    protected function injectViewComments(): void
    {
        if (! config('laravel-wisra.enabled', true)) {
            return;
        }

        if (config('laravel-wisra.local_only', true) && ! app()->isLocal()) {
            return;
        }

        if (self::$hasRegisteredBladeInstrumentation) {
            return;
        }

        self::$hasRegisteredBladeInstrumentation = true;

        $compiler = $this->app->make(BladeCompiler::class);

        $compiler->prepareStringsForCompilationUsing(function (string $value) use ($compiler): string {
            $path = $compiler->getPath();

            if (! is_string($path) || $path === '') {
                return $value;
            }

            if ($this->shouldSkipBladeInstrumentation($path)) {
                return $value;
            }

            $value = $this->annotateHtmlElementsWithLineNumbers($value);
            $value = $this->wrapStandaloneTranslationEchoes($value);

            $start = "<?php echo '<!-- [view] {$path} -->'; ?>\n";
            $end = "\n<?php echo '<!-- [/view] {$path} -->'; ?>";

            if ($this->shouldInjectViewCommentsInsideBody($value)) {
                return $this->injectViewCommentsInsideBody($value, $start, $end);
            }

            if ($this->shouldInjectViewCommentsAroundHtmlFragment($value)) {
                return $this->injectViewCommentsAroundHtmlFragment($value, $start, $end);
            }

            return $value;
        });
    }

    protected function shouldSkipBladeInstrumentation(string $path): bool
    {
        if (str_contains($path, 'storage/framework/views')) {
            return true;
        }

        if (str_contains($path, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            return true;
        }

        return false;
    }

    protected function shouldInjectViewCommentsInsideBody(string $value): bool
    {
        return (bool) preg_match('/<body\b[^>]*>.*<\/body>/is', $value);
    }

    protected function injectViewCommentsInsideBody(string $value, string $start, string $end): string
    {
        $valueWithOpeningComment = preg_replace_callback(
            '/<body\b[^>]*>/i',
            fn (array $matches): string => $matches[0]."\n".$start,
            $value,
            1,
            $openingBodyTagCount,
        );

        if (! is_string($valueWithOpeningComment) || $openingBodyTagCount === 0) {
            return $value;
        }

        $valueWithClosingComment = preg_replace_callback(
            '/<\/body>/i',
            fn (array $matches): string => $end."\n".$matches[0],
            $valueWithOpeningComment,
            1,
            $closingBodyTagCount,
        );

        if (! is_string($valueWithClosingComment) || $closingBodyTagCount === 0) {
            return $value;
        }

        return $valueWithClosingComment;
    }

    protected function shouldInjectViewCommentsAroundHtmlFragment(string $value): bool
    {
        return (bool) preg_match('/<[A-Za-z][A-Za-z0-9:-]*\b[^>]*>/i', $value);
    }

    protected function injectViewCommentsAroundHtmlFragment(string $value, string $start, string $end): string
    {
        $valueWithOpeningComment = preg_replace(
            '/(?<leading>\s*)(?<tag><[A-Za-z][A-Za-z0-9:-]*\b[^>]*>)/',
            '$1'.$start.'$2',
            $value,
            1,
            $openingTagCount,
        );

        if (! is_string($valueWithOpeningComment) || $openingTagCount === 0) {
            return $value;
        }

        $valueWithClosingComment = preg_replace(
            '/(?<tag><\/[A-Za-z][A-Za-z0-9:-]*\s*>|<[A-Za-z][A-Za-z0-9:-]*\b[^>]*\/>)(?![\s\S]*(?:<\/[A-Za-z][A-Za-z0-9:-]*\s*>|<[A-Za-z][A-Za-z0-9:-]*\b[^>]*\/>))/',
            '$1'.$end,
            $valueWithOpeningComment,
            1,
            $closingTagCount,
        );

        if (! is_string($valueWithClosingComment) || $closingTagCount === 0) {
            return $valueWithOpeningComment;
        }

        return $valueWithClosingComment;
    }

    protected function wrapStandaloneTranslationEchoes(string $value): string
    {
        $wrappedValue = preg_replace_callback(
            '/^(?<indent>[ \t]*)\{\{\s*(?:__|trans)\((?:.*?)\)\s*\}\}(?<trailing>[ \t]*)$/m',
            function (array $matches): string {
                $indent = $matches['indent'];
                $echo = trim($matches[0]);
                $translationComment = $this->buildTranslationComment($echo);

                return "{$indent}{$translationComment}\n{$indent}{$echo}\n{$indent}<!-- [/laravel-translation] -->";
            },
            $value,
        );

        return is_string($wrappedValue) ? $wrappedValue : $value;
    }

    protected function annotateHtmlElementsWithLineNumbers(string $value): string
    {
        $annotations = $this->collectHtmlElementLineAnnotations($value);

        if ($annotations === []) {
            return $value;
        }

        $openingTagIndex = 0;

        $annotatedValue = preg_replace_callback(
            '/<(?<closing>\/)?(?<name>[A-Za-z][A-Za-z0-9:-]*)(?<attributes>(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*)(?<selfClosing>\s*\/)?>/m',
            function (array $matches) use (&$openingTagIndex, $annotations): string {
                $tagName = strtolower($matches['name']);
                $attributes = $matches['attributes'] ?? '';

                if (
                    $matches['closing'] !== ''
                    || ! $this->shouldAnnotateHtmlTag($tagName)
                    || $this->containsBladeAttributeSyntax($attributes)
                ) {
                    return $matches[0];
                }

                $annotation = $annotations[$openingTagIndex] ?? null;
                $openingTagIndex++;

                if ($annotation === null || str_contains($matches[0], 'wisra-start-line=')) {
                    return $matches[0];
                }

                $suffix = preg_match('/\/>$/', $matches[0]) ? '/>' : '>';
                $tagWithoutSuffix = substr($matches[0], 0, -strlen($suffix));

                return $tagWithoutSuffix
                    .' wisra-start-line="'.$annotation['start'].'"'
                    .' wisra-end-line="'.$annotation['end'].'"'
                    .$suffix;
            },
            $value,
        );

        return is_string($annotatedValue) ? $annotatedValue : $value;
    }

    protected function containsBladeAttributeSyntax(string $attributes): bool
    {
        return str_contains($attributes, '{{')
            || str_contains($attributes, '{!!')
            || str_contains($attributes, '@');
    }

    /**
     * @return array<int, array{start: int, end: int}>
     */
    protected function collectHtmlElementLineAnnotations(string $value): array
    {
        preg_match_all(
            '/<(?<closing>\/)?(?<name>[A-Za-z][A-Za-z0-9:-]*)(?<attributes>(?:[^<>"\']+|"[^"]*"|\'[^\']*\')*)(?<selfClosing>\s*\/)?>/m',
            $value,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $annotations = [];
        $stack = [];

        foreach ($matches[0] as $index => [$fullMatch, $offset]) {
            $tagName = strtolower($matches['name'][$index][0]);

            if (! $this->shouldAnnotateHtmlTag($tagName)) {
                continue;
            }

            $line = substr_count(substr($value, 0, $offset), "\n") + 1;
            $isClosingTag = $matches['closing'][$index][0] === '/';
            $isSelfClosingTag = trim($matches['selfClosing'][$index][0]) === '/'
                || $this->isVoidHtmlTag($tagName);

            if ($isClosingTag) {
                if (! isset($stack[$tagName]) || $stack[$tagName] === []) {
                    continue;
                }

                $openingTag = array_pop($stack[$tagName]);

                if (is_array($openingTag)) {
                    $annotations[$openingTag['index']]['end'] = $line;
                }

                continue;
            }

            $annotationIndex = count($annotations);

            $annotations[$annotationIndex] = [
                'start' => $line,
                'end' => $line,
            ];

            if ($isSelfClosingTag) {
                continue;
            }

            $stack[$tagName] ??= [];
            $stack[$tagName][] = [
                'index' => $annotationIndex,
            ];
        }

        ksort($annotations);

        return array_values($annotations);
    }

    protected function shouldAnnotateHtmlTag(string $tagName): bool
    {
        return in_array($tagName, $this->htmlTagsForLineAnnotations(), true);
    }

    protected function isVoidHtmlTag(string $tagName): bool
    {
        return in_array($tagName, [
            'area',
            'base',
            'br',
            'col',
            'embed',
            'hr',
            'img',
            'input',
            'link',
            'meta',
            'param',
            'source',
            'track',
            'wbr',
        ], true);
    }

    /**
     * @return array<int, string>
     */
    protected function htmlTagsForLineAnnotations(): array
    {
        return [
            'a',
            'article',
            'aside',
            'body',
            'button',
            'div',
            'em',
            'footer',
            'form',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'head',
            'header',
            'html',
            'img',
            'input',
            'label',
            'li',
            'link',
            'main',
            'meta',
            'nav',
            'ol',
            'p',
            'section',
            'small',
            'span',
            'strong',
            'title',
            'ul',
        ];
    }

    protected function buildTranslationComment(string $echo): string
    {
        $translationPath = $this->resolveTranslationPath($echo);

        if ($translationPath === null) {
            return '<!-- [laravel-translation] -->';
        }

        return "<!-- [laravel-translation] {$translationPath} -->";
    }

    protected function resolveTranslationPath(string $echo): ?string
    {
        if (! preg_match('/\{\{\s*(?:__|trans)\(\s*([\'"])(?<key>[^\'"]+)\1/s', $echo, $matches)) {
            return null;
        }

        $key = $matches['key'];

        if (! str_contains($key, '.')) {
            return null;
        }

        $group = str($key)->before('.')->value();

        if ($group === '' || str_contains($group, '::')) {
            return null;
        }

        foreach (array_unique([app()->getLocale(), config('app.fallback_locale')]) as $locale) {
            if (! is_string($locale) || $locale === '') {
                continue;
            }

            $path = lang_path($locale.DIRECTORY_SEPARATOR.$group.'.php');

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
