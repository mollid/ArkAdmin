<?php

namespace Addons\cms\Support;

/**
 * 富文本白名单净化（文章正文存储前收口）：
 * 允许 Quill 产出的结构标签；其余标签解包保留文本，script/style/iframe 等整棵丢弃，
 * 事件属性一律剥离，href/src 仅允许 http/https/mailto/相对地址。
 * 威胁模型：持有文章发布权限的低权编辑者注入存储型脚本（管理员打开编辑时以其身份执行）。
 */
class RichTextSanitizer
{
    protected const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img',
    ];

    protected const DROP_SUBTREE = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math'];

    protected const ALLOWED_ATTR = ['href', 'src', 'alt', 'target', 'rel', 'class'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        // XML 声明强制 utf-8 解析；NOWARNING/NOERROR 容错残缺 HTML
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="ark-root">'.$html.'</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $root = $doc->getElementById('ark-root');
        if ($root === null) {
            return '';
        }
        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    protected static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_SUBTREE, true)) {
                    $node->removeChild($child);

                    continue;
                }
                self::walk($child);
                if (in_array($tag, self::ALLOWED_TAGS, true)) {
                    self::cleanAttributes($child);
                } else {
                    // 非白名单标签：解包保留其内容（div/span/table 等常见携带文本）
                    while ($child->firstChild !== null) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                // 条件注释/处理指令可承载载荷，一律丢弃
                $node->removeChild($child);
            }
        }
    }

    protected static function cleanAttributes(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $drop = ! in_array($name, self::ALLOWED_ATTR, true) || str_starts_with($name, 'on');
            if (! $drop && in_array($name, ['href', 'src'], true)) {
                $scheme = strtolower((string) (parse_url(trim((string) $attr->value), PHP_URL_SCHEME) ?? ''));
                $drop = ! in_array($scheme, ['', 'http', 'https', 'mailto'], true);
            }
            if ($drop) {
                $el->removeAttribute($attr->name);
            }
        }
    }
}
