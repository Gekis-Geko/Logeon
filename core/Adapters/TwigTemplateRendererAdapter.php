<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\Contracts\TemplateRendererInterface;
use Core\Template;

class TwigTemplateRendererAdapter implements TemplateRendererInterface
{
    public function render(string $template, array $params = [])
    {
        return Template::view($template, $params);
    }
}
