<?php

declare(strict_types=1);

namespace Modufolio\Appkit\Attributes;

/**
 * Marks a controller parameter to be resolved into a ready-to-render
 * {@see \Modufolio\Appkit\Template\Template}.
 *
 * The template and layout paths and the current request are supplied by
 * {@see \Modufolio\Appkit\Resolver\TemplateResolver}, so a controller only
 * names the template (and, optionally, the layout):
 *
 *   public function index(#[Template('home')] TemplateEngine $template): ResponseInterface
 *   {
 *       return new Response(body: $template->render(['title' => 'Welcome']));
 *   }
 *
 * @author    Maarten Thiebou
 * @copyright Modufolio
 * @license   https://opensource.org/licenses/MIT
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Template
{
    public function __construct(
        public string $name,
        public ?string $layout = null,
    ) {
    }
}
