<?php

namespace GlpiPlugin\Termodocs\Placeholder;

use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;
use Twig\Source;

/**
 * Dedicated, sandboxed Twig environment used to resolve `{{ field }}`
 * placeholders inside admin-authored document templates.
 *
 * This is intentionally a separate Twig\Environment from
 * Glpi\Application\View\TemplateRenderer (the core UI renderer): the core
 * one carries globals/extensions meant for trusted application templates,
 * not for untrusted-ish, user-authored document bodies.
 *
 * Defense in depth: only plain arrays are ever passed as context (never
 * CommonDBTM objects), and the sandbox policy only allows `if`/`for` tags,
 * a short filter allow-list, and no method/property access at all - so
 * even `{% for item in items %}{{ item.foo.bar }}{% endfor %}` can only
 * ever read plain array keys, never call into PHP objects.
 */
class TemplateRenderer
{
    private static ?self $instance = null;

    private Environment $twig;
    private ArrayLoader $loader;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->loader = new ArrayLoader();
        $this->twig = new Environment($this->loader, [
            'autoescape'       => 'html',
            'strict_variables' => false,
            'cache'            => false,
        ]);

        $policy = new SecurityPolicy(
            allowedTags: ['if', 'for'],
            allowedFilters: ['date', 'upper', 'lower', 'default', 'trim', 'nl2br', 'escape', 'raw', 'length', 'first', 'last', 'join'],
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: []
        );
        $this->twig->addExtension(new SandboxExtension($policy, true));
    }

    public function render(string $html, array $context): string
    {
        $name = 'termodocs_' . md5($html);
        $this->loader->setTemplate($name, $html);

        try {
            return $this->twig->render($name, $context);
        } catch (TwigError $e) {
            throw new UnsafeTemplateException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return string[] syntax/security error messages, empty when the
     * template is safe to save.
     */
    public function lint(string $html): array
    {
        try {
            $this->twig->parse($this->twig->tokenize(new Source($html, 'termodocs_lint')));
        } catch (TwigError $e) {
            return [$e->getMessage()];
        }

        return [];
    }
}
