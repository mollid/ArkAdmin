<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('settings.manage.title') }}</span>
        <el-button v-permission="'system.setting.update'" type="primary" @click="save">
          {{ t('common.confirm') }}
        </el-button>
      </div>
    </template>

    <el-empty v-if="!groups.length" />
    <el-form v-else :model="form" label-width="120px">
      <div v-for="(items, addon) in groups" :key="addon" class="group">
        <div class="group-title">{{ addon === 'system' ? t('settings.manage.groupSystem') : addon }}</div>
        <el-form-item v-for="item in items" :key="item.key" :label="item.label">
          <el-input v-if="item.type === 'text'" v-model="form[item.key]" maxlength="191" />
          <el-input v-else-if="item.type === 'textarea'" v-model="form[item.key]" type="textarea" :rows="3" />
          <el-input-number v-else-if="item.type === 'number'" v-model="form[item.key]" />
          <el-switch v-else-if="item.type === 'boolean'" v-model="form[item.key]" />
          <el-input v-else v-model="form[item.key]" />
        </el-form-item>
      </div>
    </el-form>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import request from '@/app/api/request'

/* 系统设置管理页：消费核心 settings 基建（GET/PUT /api/admin/settings，schema 白名单） */

interface SettingEntry {
  key: string
  label: string
  type: string
  default: unknown
  value: unknown
  addon: string
  scope: 'system' | 'plugin'
}

const { t } = useI18n()
const form = reactive<Record<string, unknown>>({})

const groups = computed<Record<string, SettingEntry[]>>(() => {
  const out: Record<string, SettingEntry[]> = {}
  for (const entry of entries.value) {
    ;(out[entry.scope === 'system' ? 'system' : entry.addon] ??= []).push(entry)
  }
  return out
})

const entries = ref<SettingEntry[]>([])

async function load() {
  entries.value = await request.get<never, SettingEntry[]>('/settings')
  for (const entry of entries.value) {
    form[entry.key] = entry.value
  }
}

async function save() {
  try {
    await request.put('/settings', { values: { ...form } })
  } catch {
    return // 白名单校验失败已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  load()
}

onMounted(load)
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
.group-title { font-weight: 600; margin: 12px 0; color: var(--el-text-color-secondary); }
</style>
