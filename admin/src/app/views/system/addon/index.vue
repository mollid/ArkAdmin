<template>
  <el-card>
    <template #header>
      <div class="header">
        <span>插件管理</span>
        <el-button link type="primary" @click="load">刷新</el-button>
      </div>
    </template>

    <el-alert v-if="notice" :title="notice" type="warning" :closable="false" show-icon style="margin-bottom: 12px" />

    <el-table :data="rows" border>
      <el-table-column prop="name" label="标识" min-width="110" />
      <el-table-column label="名称" min-width="130">
        <template #default="{ row }">
          <span>{{ row.title }}</span>
          <span v-if="row.description" class="desc">（{{ row.description }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="版本" min-width="130">
        <template #default="{ row }">
          <span>{{ row.installed ? row.installed_version : '—' }}</span>
          <el-tag v-if="row.upgradable" type="primary" size="small" style="margin-left: 6px">
            → {{ row.version }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="依赖" min-width="110">
        <template #default="{ row }">
          <span>{{ row.dependencies.length ? row.dependencies.join('、') : '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="140">
        <template #default="{ row }">
          <el-tag :type="addonStatus(row).type">{{ addonStatus(row).text }}</el-tag>
          <el-tag v-if="row.system" type="info" size="small" style="margin-left: 6px">系统</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="install_time" label="安装时间" min-width="150" />
      <el-table-column label="操作" width="220" fixed="right">
        <template #default="{ row }">
          <el-button v-permission="'system.addon.store'" v-if="!row.installed && !row.disk_missing"
            link type="success" @click="install(row)">安装</el-button>
          <template v-if="row.installed && !row.disk_missing">
            <el-button v-permission="'system.addon.update'" v-if="row.upgradable"
              link type="primary" @click="upgrade(row)">升级</el-button>
            <el-button v-permission="'system.addon.update'" v-if="row.enabled && !row.system"
              link type="warning" @click="toggle(row, 'disable')">禁用</el-button>
            <el-button v-permission="'system.addon.update'" v-if="!row.enabled"
              link type="success" @click="toggle(row, 'enable')">启用</el-button>
            <el-button v-permission="'system.addon.destroy'" v-if="!row.enabled && !row.system"
              link type="danger" @click="remove(row)">卸载</el-button>
          </template>
        </template>
      </el-table-column>
    </el-table>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { addonApi, type AddonAction } from '../../../api/addon'
import { addonStatus, buildNotice, type AddonRow } from '../../../utils/addon'

const { t } = useI18n()
const rows = ref<AddonRow[]>([])
const notice = ref('')

async function load() {
  rows.value = await addonApi.list()
}

/** 统一执行写操作：成功后按 needs_build 挂顶部持久提示并刷新列表 */
async function run(fn: () => Promise<{ needs_build?: boolean }>, okMsg = '') {
  try {
    const resp = await fn()
    notice.value = buildNotice(resp)
    if (okMsg) ElMessage.success(okMsg)
    await load()
  } catch {
    // 拦截器已提示
  }
}

function install(row: AddonRow) {
  return run(() => addonApi.install(row.name), '安装成功')
}

function toggle(row: AddonRow, action: AddonAction) {
  return run(() => addonApi.update(row.name, action), action === 'enable' ? '已启用' : '已禁用')
}

function upgrade(row: AddonRow) {
  return run(() => addonApi.update(row.name, 'upgrade'), '升级成功')
}

async function remove(row: AddonRow) {
  const keepData = await confirmUninstall(row)
  if (keepData === null) return
  return run(() => addonApi.uninstall(row.name, keepData), '卸载成功')
}

/** 卸载确认三选（distinguishCancelAndClose）：confirm=全部回滚 / cancel=保留数据 / close=放弃，null=取消 */
async function confirmUninstall(row: AddonRow): Promise<boolean | null> {
  const deps = row.dependents.length > 0 ? `（正被 ${row.dependents.join('、')} 依赖）` : ''
  try {
    await ElMessageBox.confirm(
      `卸载将回滚插件业务表${deps}，确定卸载「${row.title}」？`,
      t('common.tip'),
      { type: 'warning', distinguishCancelAndClose: true,
        cancelButtonText: '保留数据', confirmButtonText: '全部回滚' },
    )
    return false
  } catch (action) {
    return action === 'cancel' ? true : null
  }
}

onMounted(load)
</script>

<style scoped>
.header { display: flex; align-items: center; justify-content: space-between; }
.desc { color: var(--el-text-color-secondary); font-size: 12px; }
</style>
