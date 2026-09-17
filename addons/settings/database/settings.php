<?php

/*
 * settings schema 声明（harness 规格 §3.2）：归属 = 本插件，scope=system 的键为系统共用（所有插件可读）。
 * 管理页按这里的声明渲染表单；存储走核心 SettingStore（settings 表）。
 */
return [
    ['key' => 'site.name', 'label' => '站点名称', 'type' => 'text', 'default' => 'ArkAdmin', 'scope' => 'system'],
    ['key' => 'site.description', 'label' => '站点描述', 'type' => 'textarea', 'default' => '', 'scope' => 'system'],
];
