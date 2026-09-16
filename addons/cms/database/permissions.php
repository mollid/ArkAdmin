<?php

/* CMS 全部权限串（§5.3：addon.<key>.<controller>.<action>），安装时 module=cms 建入 spatie */
return [
    'addon.cms.category.index',
    'addon.cms.category.store',
    'addon.cms.category.update',
    'addon.cms.category.destroy',
    'addon.cms.article.index',
    'addon.cms.article.store',
    'addon.cms.article.update',
    'addon.cms.article.destroy',
// ark:crud:cms_notices:start
    'addon.cms.notice.index',
    'addon.cms.notice.store',
    'addon.cms.notice.update',
    'addon.cms.notice.destroy',
// ark:crud:cms_notices:end
];
