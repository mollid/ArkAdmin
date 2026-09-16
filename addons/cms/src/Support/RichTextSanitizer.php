<?php

namespace Addons\cms\Support;

/**
 * 富文本白名单净化（文章正文存储前收口）：
 * 允许 Quill 产出的结构标签；其余标签解包保留文本，script/style/iframe 等整棵丢弃，
 * 事件属性一律剥离，href/src 仅允许 http/https/mailto/相对地址。
 *
 * 同时必须保留 Quill 2 的语义标记，否则正文「保存即降级」（白名单只挡载荷、不砍合法产出）：
 * - 列表：Quill 2 的 ListContainer 固定是 OL，类型由 li[data-list] 区分（bullet/ordered/checked/unchecked）；
 *   而它的剪贴板匹配器 matchList 只按容器标签判型（OL→有序、UL→无序），故非有序列表需改写容器为 UL，
 *   li[data-list] 本身也要留存（其他渲染方与后续编辑器据此恢复类型）。
 * - 代码块：Quill 2 的 CodeBlock 是 DIV（div.ql-code-block-container / div.ql-code-block），不是 Quill 1 的 PRE。
 *
 * 威胁模型：持有文章发布权限的低权编辑者注入存储型脚本（管理员打开编辑时以其身份执行）。
 */
class RichTextSanitizer
{
    protected const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a', 'img',
    ];

    protected const DROP_SUBTREE = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'svg', 'math'];

    protected const ALLOWED_ATTR = ['href', 'src', 'alt', 'target', 'rel', 'class', 'data-list'];

    /** Quill 2 的 li[data-list] 取值白名单（其余取值剥离后该 li 退回外部 HTML 语义） */
    protected const LIST_TYPES = ['bullet', 'ordered', 'checked', 'unchecked'];

    /** 承载 Quill 2 代码块语义的 div class：仅这些 div 保留结构，其余 div 仍解包 */
    protected const KEEP_DIV_CLASSES = ['ql-code-block-container', 'ql-code-block'];

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
                    if ($tag === 'ul' || $tag === 'ol') {
                        self::normalizeListContainer($child);
                    }
                } elseif ($tag === 'div' && self::holdsQuillStructure($child)) {
                    // Quill 2 代码块结构：保留 div（解包会连 class 一起丢，代码块即消失）
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
            if (! $drop && $name === 'data-list') {
                $drop = ! in_array($attr->value, self::LIST_TYPES, true);
            }
            if ($drop) {
                $el->removeAttribute($attr->name);
            }
        }
    }

    /** 是否带 Quill 2 代码块 class（这些 div 承载结构语义，必须保留） */
    protected static function holdsQuillStructure(\DOMElement $el): bool
    {
        $classes = preg_split('/\s+/', (string) $el->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($classes, self::KEEP_DIV_CLASSES) !== [];
    }

    /**
     * Quill 2 全用 OL 作列表容器、靠 li[data-list] 区分类型，而它的剪贴板只按容器标签判型，
     * 因此把非有序列表的容器改写为 UL（data-list 保留在 li 上供其他渲染方使用）。
     * 没有 data-list（外部 HTML）或含 ordered 项时保持原标签，交给 Quill 自行归一。
     */
    protected static function normalizeListContainer(\DOMElement $el): void
    {
        $types = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'li') {
                $types[] = $child->getAttribute('data-list');
            }
        }
        $types = array_values(array_filter($types, fn (string $t): bool => $t !== ''));
        if ($types === [] || in_array('ordered', $types, true)) {
            return;
        }
        if (strtolower($el->tagName) === 'ol') {
            self::renameElement($el, 'ul');
        }
    }

    /** DOMDocument 的 tagName 只读：新建同名节点搬走属性与子节点后替换 */
    protected static function renameElement(\DOMElement $el, string $tag): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        $new = $el->ownerDocument->createElement($tag);
        foreach (iterator_to_array($el->attributes) as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        while ($el->firstChild !== null) {
            $new->appendChild($el->firstChild);
        }
        $parent->replaceChild($new, $el);
    }
}
