<?php

namespace Addons\cms\Support;

/**
 * CMS 业务守卫异常（父级环路、栏目删除前置条件等）。
 * 控制器捕获后转 fail(1, msg)：HTTP 200 + code!=0，沿用框架业务错误约定。
 */
class CmsException extends \RuntimeException
{
}
