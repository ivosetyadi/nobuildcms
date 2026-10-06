<?php

declare(strict_types=1);

namespace NoBuildCMS;

use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Central service: config, store, Twig environments, and the content renderer.
 *
 * Two Twig environments:
 *   - $view    : our own trusted admin/site templates (autoescaped).
 *   - $content : sandboxed renderer for user-authored content bodies.
 */
final class App
{
    public Store $store;
    public array $settings;
    public array $visitor = ['name' => 'Tamu', 'id' => null];

    private Environment $view;
    private Environment $content;

    public function __construct(public string $root)
    {
        $this->store = new Store($root . '/data');
        $this->settings = $this->store->read('settings');
        $this->buildViewEnv();
        $this->buildContentEnv();
    }

    public function setVisitor(array $visitor): void
    {
        $this->visitor = $visitor;
    }

    // ---- public API -------------------------------------------------------

    public function render(string $template, array $ctx = []): string
    {
        return $this->view->render($template, $this->globals($ctx));
    }

    /** Render a content record's Twig body inside the sandbox, return HTML. */
    public function renderBody(array $record): string
    {
        $tpl = $this->content->createTemplate($record['body'] ?? '', 'content:' . ($record['id'] ?? '?'));

        return $tpl->render($this->globals(['item' => $this->decorate($record)]));
    }

    /** Resolve a public URL to a content record, across all content types. */
    public function resolve(string $type, string $slug): ?array
    {
        $r = $this->store->find($type, $slug);

        return $r ? $this->decorate($r) : null;
    }

    public function decorate(array $r): array
    {
        $r['url'] = $this->urlFor($r);

        return $r;
    }

    public function urlFor(array $r): string
    {
        $slug = $r['slug'] ?? '';
        return match ($r['type'] ?? 'page') {
            'post' => '/blog/' . $slug,
            'product' => '/produk/' . $slug,
            default => $slug === 'home' ? '/' : '/' . $slug,
        };
    }

    // ---- Twig environments ------------------------------------------------

    private function globals(array $ctx): array
    {
        return array_merge([
            'settings' => $this->settings,
            'visitor' => $this->visitor,
            'now' => date('c'),
        ], $ctx);
    }

    private function sharedFunctions(): array
    {
        return [
            new TwigFunction('collection', [$this, 'fnCollection']),
            new TwigFunction('record', [$this, 'fnRecord']),
            new TwigFunction('data', [$this, 'fnData']),
            new TwigFunction('snippet', [$this, 'fnSnippet'], ['is_safe' => ['html']]),
            new TwigFunction('attrs', [$this, 'fnAttrs'], ['is_safe' => ['html']]),
            new TwigFunction('setting', fn (string $k, $d = null) => $this->settings[$k] ?? $d),
        ];
    }

    private function sharedFilters(): array
    {
        return [
            new TwigFilter('money', [$this, 'filterMoney']),
            new TwigFilter('fdate', [$this, 'filterDate']),
        ];
    }

    private function buildViewEnv(): void
    {
        $env = new Environment(new FilesystemLoader($this->root . '/templates'), [
            'autoescape' => 'html',
            'cache' => false,
        ]);
        foreach ($this->sharedFunctions() as $f) {
            $env->addFunction($f);
        }
        foreach ($this->sharedFilters() as $f) {
            $env->addFilter($f);
        }
        $this->view = $env;
    }

    private function buildContentEnv(): void
    {
        $env = new Environment(new ArrayLoader(), [
            'autoescape' => false, // authors write raw HTML
            'cache' => false,
        ]);
        foreach ($this->sharedFunctions() as $f) {
            $env->addFunction($f);
        }
        foreach ($this->sharedFilters() as $f) {
            $env->addFilter($f);
        }

        $policy = new SecurityPolicy(
            allowedTags: ['if', 'for', 'set', 'block', 'filter', 'apply', 'verbatim', 'autoescape', 'spaceless'],
            allowedFilters: [
                'default', 'escape', 'e', 'raw', 'length', 'upper', 'lower', 'title', 'capitalize',
                'trim', 'nl2br', 'join', 'split', 'replace', 'slice', 'first', 'last', 'keys',
                'merge', 'sort', 'reverse', 'number_format', 'date', 'round', 'abs', 'striptags',
                'url_encode', 'json_encode', 'format', 'map', 'filter', 'batch', 'column',
                'money', 'fdate',
            ],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: [
                'collection', 'record', 'data', 'snippet', 'attrs', 'setting',
                'range', 'max', 'min', 'cycle', 'random', 'date',
            ],
        );
        $env->addExtension(new SandboxExtension($policy, true));

        $this->content = $env;
    }

    // ---- Twig callbacks ---------------------------------------------------

    /** collection(type, {status,q,tag,where,sort,limit,offset}) */
    public function fnCollection(string $type, array $opts = []): array
    {
        $rows = array_map([$this, 'decorate'], $this->store->all($type));

        $status = $opts['status'] ?? 'published';
        if ($status !== '*') {
            $rows = array_filter($rows, fn ($r) => ($r['status'] ?? 'draft') === $status);
        }
        if (!empty($opts['tag'])) {
            $rows = array_filter($rows, fn ($r) => in_array($opts['tag'], $r['tags'] ?? [], true));
        }
        if (!empty($opts['q'])) {
            $q = mb_strtolower((string) $opts['q']);
            $rows = array_filter($rows, fn ($r) => str_contains(mb_strtolower(($r['title'] ?? '') . ' ' . ($r['excerpt'] ?? '')), $q));
        }
        if (!empty($opts['where']) && is_array($opts['where'])) {
            foreach ($opts['where'] as $path => $val) {
                $rows = array_filter($rows, fn ($r) => self::dot($r, $path) == $val);
            }
        }
        if (!empty($opts['sort'])) {
            $field = (string) $opts['sort'];
            $desc = str_starts_with($field, '-');
            $field = ltrim($field, '-');
            usort($rows, function ($a, $b) use ($field) {
                return self::dot($a, $field) <=> self::dot($b, $field);
            });
            if ($desc) {
                $rows = array_reverse($rows);
            }
        }
        $rows = array_values($rows);
        $offset = (int) ($opts['offset'] ?? 0);
        $limit = isset($opts['limit']) ? (int) $opts['limit'] : null;

        return $limit !== null ? array_slice($rows, $offset, $limit) : array_slice($rows, $offset);
    }

    public function fnRecord(string $type, string $slug): ?array
    {
        $r = $this->store->find($type, $slug);

        return $r ? $this->decorate($r) : null;
    }

    public function fnData(string $name): array
    {
        foreach ($this->store->all('datasets') as $d) {
            if (($d['key'] ?? $d['slug'] ?? null) === $name) {
                return $d['rows'] ?? [];
            }
        }

        return [];
    }

    public function fnSnippet(string $name, array $vars = []): string
    {
        foreach ($this->store->all('snippets') as $s) {
            if (($s['key'] ?? $s['slug'] ?? null) === $name) {
                $tpl = $this->content->createTemplate($s['body'] ?? '', 'snippet:' . $name);

                return $tpl->render($this->globals($vars));
            }
        }

        return '';
    }

    public function fnAttrs(array $map): string
    {
        $out = [];
        foreach ($map as $k => $v) {
            if ($v === true) {
                $out[] = htmlspecialchars((string) $k);
            } elseif ($v === false || $v === null) {
                continue;
            } else {
                $out[] = htmlspecialchars((string) $k) . '="' . htmlspecialchars((string) $v) . '"';
            }
        }

        return implode(' ', $out);
    }

    public function filterMoney($value): string
    {
        $n = number_format((float) $value, 0, ',', '.');
        $cur = $this->settings['currency'] ?? 'IDR';

        return $cur === 'IDR' ? 'Rp ' . $n : $cur . ' ' . $n;
    }

    public function filterDate($value, string $fmt = 'd M Y'): string
    {
        try {
            $dt = $value instanceof \DateTimeInterface ? $value : new \DateTime((string) $value);

            return $dt->format($fmt);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function dot(array $arr, string $path)
    {
        foreach (explode('.', $path) as $seg) {
            if (is_array($arr) && array_key_exists($seg, $arr)) {
                $arr = $arr[$seg];
            } else {
                return null;
            }
        }

        return $arr;
    }
}
