<?php
/*
 * This file is part of the Cortex package.
 *
 * (c) Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Brain\Cortex\Router;

use Brain\Cortex\Controller\ControllerInterface;

/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @license http://opensource.org/licenses/MIT MIT
 * @package Cortex
 */
final class ResultHandler implements ResultHandlerInterface
{
    /**
     * @inheritdoc
     */
    public function handle(MatchingResult $result, \WP $wp, $doParseRequest)
    {
        $result = apply_filters('cortex.match.done', $result, $wp, $doParseRequest);
        $handlerResult = $doParseRequest;

        if ($result->matched()) {
            $doParseRequest = false;
            $origHandler = $result->handler();
            $handler = $this->buildCallback($origHandler);
            $before = $this->buildCallback($result->beforeHandler());
            $after = $this->buildCallback($result->afterHandler());
            $template = $result->template();
            (is_string($template)) or $template = '';
            $vars = $result->vars();

            do_action('cortex.matched', $result, $wp);

            is_callable($before) and $before($vars, $wp, $template);
            is_callable($handler) and $handlerResult = $handler($vars, $wp, $template);
            is_callable($after) and $after($vars, $wp, $template);
            $template and $this->setTemplate($template);

            do_action('cortex.matched-after', $result, $wp, $handlerResult);

            is_bool($handlerResult) and $doParseRequest = $handlerResult;
            $doParseRequest = apply_filters('cortex.do-parse-request', $doParseRequest);

            if (! $doParseRequest) {
                remove_filter('template_redirect', 'redirect_canonical');

                return false;
            }
        }

        do_action('cortex.result.done', $result, $wp, $handlerResult);

        return $doParseRequest;
    }

    /**
     * @param  mixed         $handler
     * @return callable|null
     */
    private function buildCallback($handler)
    {
        $built = null;
        if (is_callable($handler)) {
            $built = $handler;
        }

        if (! $built && $handler instanceof ControllerInterface) {
            $built = function (array $vars, \WP $wp) use ($handler) {
                return $handler->run($vars, $wp);
            };
        }

        return $built;
    }

    /**
     * @param $template
     */
    private function setTemplate($template)
    {
        $ext = apply_filters('cortex.default-template-extension', 'php');
        pathinfo($template, PATHINFO_EXTENSION) or $template .= '.'.ltrim($ext, '.');
        $template = is_file($template) ? $template : locate_template([$template], false);
        if (! $template) {
            return;
        }

        $setter = function () use ($template) {
            return $template;
        };

        $types = [
            '404',
            'search',
            'front_page',
            'home',
            'archive',
            'taxonomy',
            'attachment',
            'single',
            'page',
            'singular',
            'category',
            'tag',
            'author',
            'date',
            'paged',
            'index',
        ];

        array_walk($types, function ($type) use ($setter) {
            add_filter("{$type}_template", $setter);
        });

        add_filter('template_include', function () use ($template) {
            remove_all_filters('template_include');

            return $template;
        }, -1);
    }
}
