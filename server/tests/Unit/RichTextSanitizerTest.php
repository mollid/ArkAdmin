<?php

use Addons\cms\Support\RichTextSanitizer;

// 评审轮 R2-1 回归：净化必须「只砍载荷、不砍合法产出」——Quill 2 的列表类型与代码块结构
// 一旦被剥离，正文在保存瞬间就降级（项目符号/待办变有序列表、代码块变纯文本）。

it('保留 Quill 2 列表类型：非有序列表容器改写为 UL，有序保持 OL', function () {
    // Quill 2 内部表示：容器恒为 OL，类型在 li[data-list]（ListItem.formats 读它）
    expect(RichTextSanitizer::clean('<ol><li data-list="bullet">甲</li><li data-list="bullet">乙</li></ol>'))
        ->toBe('<ul><li data-list="bullet">甲</li><li data-list="bullet">乙</li></ul>')
        ->and(RichTextSanitizer::clean('<ol><li data-list="checked">待办</li><li data-list="unchecked">未办</li></ol>'))
        ->toBe('<ul><li data-list="checked">待办</li><li data-list="unchecked">未办</li></ul>')
        ->and(RichTextSanitizer::clean('<ol><li data-list="ordered">一</li></ol>'))
        ->toBe('<ol><li data-list="ordered">一</li></ol>');
});

it('无 data-list 的外部 HTML 与类型混杂时不动容器标签', function () {
    // 外部 HTML 的 UL/OL 语义由容器标签自身表达，Quill 剪贴板也按标签判型，无需改写
    expect(RichTextSanitizer::clean('<ul><li>外部无序</li></ul>'))
        ->toBe('<ul><li>外部无序</li></ul>')
        ->and(RichTextSanitizer::clean('<ol><li>外部有序</li></ol>'))
        ->toBe('<ol><li>外部有序</li></ol>')
        ->and(RichTextSanitizer::clean('<ol><li data-list="ordered">一</li><li data-list="bullet">甲</li></ol>'))
        ->toBe('<ol><li data-list="ordered">一</li><li data-list="bullet">甲</li></ol>');
});

it('保留 Quill 2 代码块 DIV 结构与对齐/缩进 class', function () {
    $code = '<div class="ql-code-block-container"><div class="ql-code-block">echo 1;</div></div>';
    expect(RichTextSanitizer::clean($code))->toBe($code)
        ->and(RichTextSanitizer::clean('<p class="ql-align-center ql-indent-1">居中缩进</p>'))
        ->toBe('<p class="ql-align-center ql-indent-1">居中缩进</p>');
});

it('新增保留规则不放宽安全边界：非法 list 值、伪装 class、载荷仍被剥离', function () {
    // data-list 取值不在白名单 → 剥离，容器也不再满足改写条件
    expect(RichTextSanitizer::clean('<ol><li data-list="javascript:alert(1)">x</li></ol>'))
        ->toBe('<ol><li>x</li></ol>')
        // 仅精确匹配 Quill 代码块 class，伪装的 class 仍解包
        ->and(RichTextSanitizer::clean('<div class="ql-code-block-evil">x</div>'))->toBe('x')
        ->and(RichTextSanitizer::clean('<div class="ql-code-block" onclick="alert(1)">'
            .'<script>alert(2)</script><a href="javascript:alert(3)">ok</a></div>'))
        ->toBe('<div class="ql-code-block"><a>ok</a></div>');
});
