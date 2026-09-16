<?php
$in = '<ol><li data-list="bullet" class="ql-indent-1">甲</li></ol>'
    .'<div class="ql-code-block-container" spellcheck="false"><div class="ql-code-block" data-language="php">echo 1;</div></div>';
echo "IN : $in\n";
echo 'OUT: '.\Addons\cms\Support\RichTextSanitizer::clean($in)."\n";
echo 'DOM: '.var_export(class_exists(\DOMDocument::class), true)."\n";
