<?php

declare(strict_types=1);

namespace Carl\Core;

use RuntimeException;

/**
 * Plain PHP templates. No build step, nothing to compile (hosting Section 3).
 *
 * Output escaping is not optional (hosting Section 8.5): templates call
 * $this->e() -- or the shorthand e() bound into scope -- on every value.
 */
final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private string $viewPath, private App $app)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $body = $this->partial($template, $data);
        $layout = $data['layout'] ?? 'layout';
        if ($layout === false) {
            return $body;
        }
        return $this->partial((string) $layout, $data + ['content' => $body]);
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . \str_replace('..', '', $template) . '.php';
        if (!\is_file($file)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        $scope = $this->shared;
        foreach ($data as $key => $value) {
            $scope[$key] = $value;
        }
        $scope['app']  = $this->app;
        $scope['view'] = $this;

        \ob_start();
        (function (array $__scope, string $__file): void {
            \extract($__scope, \EXTR_SKIP);
            require $__file;
        })($scope, $file);

        return (string) \ob_get_clean();
    }

    public function e(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return \htmlspecialchars((string) $value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /** For a value going into a JS/JSON island in a template. */
    public function json(mixed $value): string
    {
        $encoded = \json_encode(
            $value,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG | \JSON_HEX_AMP
            | \JSON_HEX_APOS | \JSON_HEX_QUOT
        );
        return $encoded === false ? 'null' : $encoded;
    }
}
