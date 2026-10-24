<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render;

use SW\Calendar\Application\Port\CacheInterface;
use SW\Calendar\Application\Port\TemplateRepositoryInterface;
use SW\Calendar\Application\Render\Template\CompiledTemplate;
use SW\Calendar\Application\Render\Template\CssSanitizer;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\Template\TemplateValidationException;
use SW\Calendar\Domain\Template\Template;
use SW\Calendar\Domain\Template\TemplateKind;
use SW\Calendar\Support\LoggerInterface;

/**
 * Loads templates with the persistent AST cache (SPECIFICATIONS.md §11.3). The key contains a hash of body, CSS,
 * engine and allowlist version, so every change switches the key; stale entries simply expire.
 */
final class TemplateProvider
{
    private const TTL = 30 * 86400;

    /** @var array<string, ResolvedTemplate> Per request memo (templates are immutable per revision). */
    private array $memo = [];
    public int $cacheHits = 0;
    public int $cacheMisses = 0;

    public function __construct(
        private readonly TemplateRepositoryInterface $templates,
        private readonly PresetLibrary $presets,
        private readonly TemplateCompiler $compiler,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $fallbackPreset Preset used when `$id` is null, unknown, of another kind or invalid.
     */
    public function resolve(?int $id, TemplateKind $kind, string $fallbackPreset): ResolvedTemplate
    {
        if ($id !== null && $id > 0) {
            $template = $this->templates->find($id);
            if ($template !== null && $template->kind === $kind) {
                $resolved = $this->fromTemplate($template);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }
        return $this->preset($fallbackPreset, $kind);
    }

    public static function cacheKey(int $templateId, string $body, string $css): string
    {
        return 'swc:tpl:' . $templateId . ':' . hash('sha256', $body . '|' . $css . '|' . TemplateCompiler::ENGINE_VERSION . '|' . TemplateCompiler::ALLOWLIST_VERSION);
    }

    private function fromTemplate(Template $template): ?ResolvedTemplate
    {
        $scope = 'swc-tpl-' . $template->id;
        try {
            [$compiled, $css] = $this->compileCached($template->id, $template->kind, $template->body, $template->css, $scope);
        } catch (TemplateValidationException | \InvalidArgumentException $e) {
            // Saved templates are validated on save; this only happens after an allowlist tightening.
            $this->logger->log('warning', 'template_invalid', 'Stored template no longer validates; preset used.', ['template_id' => $template->id]);
            return null;
        }
        return new ResolvedTemplate($template->id, $template->revision, $compiled, $scope, $css);
    }

    private function preset(string $key, TemplateKind $kind): ResolvedTemplate
    {
        $body = $this->presets->body($key, $kind) ?? $this->presets->body(PresetLibrary::CLASSIC_LIST, $kind) ?? '';
        $css = $this->presets->css($key, $kind);
        $scope = 'swc-preset-' . $key . '-' . $kind->value;
        [$compiled, $scoped] = $this->compileCached(0, $kind, $body, $css, $scope);
        return new ResolvedTemplate(0, 0, $compiled, $scope, $scoped);
    }

    /**
     * @return array{0: CompiledTemplate, 1: string}
     */
    private function compileCached(int $id, TemplateKind $kind, string $body, string $css, string $scope): array
    {
        $key = self::cacheKey($id, $body, $css) . ':' . $scope;
        if (isset($this->memo[$key])) {
            return [$this->memo[$key]->compiled, $this->memo[$key]->scopedCss];
        }
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $compiled = CompiledTemplate::fromJson($cached);
            if ($compiled !== null && $compiled->kind === $kind) {
                $this->cacheHits++;
                $this->memo[$key] = new ResolvedTemplate($id, 0, $compiled, $scope, $compiled->css);
                return [$compiled, $compiled->css];
            }
            // Corrupt or foreign entry: fall back to a fresh compile.
            $this->cache->delete($key);
        }
        $this->cacheMisses++;
        $compiled = $this->compiler->compile($body, $kind->value);
        $scoped = trim($css) === '' ? '' : CssSanitizer::scope($css, '.' . $scope);
        $compiled = new CompiledTemplate($compiled->kind, $compiled->nodes, $scoped);
        $this->cache->set($key, $compiled->toJson(), self::TTL);
        $this->memo[$key] = new ResolvedTemplate($id, 0, $compiled, $scope, $scoped);
        return [$compiled, $scoped];
    }
}
