<?php

declare(strict_types=1);

namespace SW\Calendar\Application\Render\Template;

interface TemplateCompilerInterface
{
    /**
     * @throws TemplateValidationException
     */
    public function compile(string $template, string $kind): CompiledTemplate;
}
