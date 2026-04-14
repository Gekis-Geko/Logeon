<?php

declare(strict_types=1);

namespace Core\Contracts;

interface TemplateRendererInterface
{
    /** @param array<string,mixed> $params */
    public function render(string $template, array $params = []);
}
