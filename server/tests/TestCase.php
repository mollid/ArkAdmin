<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 插件前端产物隔离（M3 评审轮修复）：默认 admin_path 指向真实 admin/，
        // 而测试的 install/uninstall 会复制/删除其中内容——不隔离就会写坏开发者工作区
        // （AddonFrontendSyncTest 等文件级 before 可再覆盖到各自 fixture 目录）
        config(['arkadmin.admin_path' => $this->testAdminPath()]);
    }

    protected function tearDown(): void
    {
        // 文件系统不随 RefreshDatabase 的事务回滚，测试自己的产物自己清
        $this->deleteDir($this->testAdminPath());

        parent::tearDown();
    }

    protected function testAdminPath(): string
    {
        return storage_path('framework/admin-test');
    }

    protected function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
