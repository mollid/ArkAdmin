<template>
  <el-card>
    <template #header>
      <div class="header">
        <span>素材库</span>
        <div class="actions">
          <el-input v-model="keyword" clearable :placeholder="t('common.search')" style="width: 200px"
            @keyup.enter="load(1)" @clear="load(1)" />
          <el-button @click="load(1)">{{ t('common.search') }}</el-button>
          <el-upload v-permission="'system.attachment.store'" :show-file-list="false" :http-request="doUpload"
            multiple style="margin-left: 8px">
            <el-button type="primary">{{ t('common.upload') }}</el-button>
          </el-upload>
        </div>
      </div>
    </template>

    <el-empty v-if="!rows.length" />
    <div v-else class="grid">
      <div v-for="row in rows" :key="row.id" class="cell">
        <el-image :src="row.url" fit="cover" class="thumb" :preview-src-list="[row.url]"
          preview-teleported hide-on-click-modal />
        <div class="name" :title="row.name">{{ row.name }}</div>
        <div class="meta">
          <span>{{ formatFileSize(row.size) }}</span>
          <span>{{ row.width && row.height ? `${row.width}×${row.height}` : row.mime }}</span>
        </div>
        <div class="ops">
          <el-button link type="primary" @click="copy(row)">{{ t('common.copy') }}</el-button>
          <el-button v-permission="'system.attachment.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </div>
      </div>
    </div>

    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="page" :page-size="15" @current-change="load()" />
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import { attachmentApi, type AttachmentRow } from '../../api/attachment'
import { formatFileSize } from '../../utils/attachment'

const { t } = useI18n()
const rows = ref<AttachmentRow[]>([])
const total = ref(0)
const page = ref(1)
const keyword = ref('')

async function load(p?: number) {
  if (p) page.value = p
  const data = await attachmentApi.list({ page: page.value, keyword: keyword.value })
  rows.value = data.list
  total.value = data.total
}

async function doUpload(options: UploadRequestOptions) {
  try {
    await attachmentApi.upload(options.file as File)
    ElMessage.success(t('common.success'))
    load(1)
  } catch {
    // 拦截器已提示
  }
}

async function copy(row: AttachmentRow) {
  await navigator.clipboard.writeText(row.url)
  ElMessage.success(t('common.copied'))
}

async function remove(row: AttachmentRow) {
  try {
    await ElMessageBox.confirm(`确认删除素材 ${row.name}？`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  await attachmentApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(() => load(1))
</script>

<style scoped>
.header { display: flex; align-items: center; justify-content: space-between; }
.actions { display: flex; align-items: center; gap: 8px; }
.grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; }
.cell { border: 1px solid var(--el-border-color-lighter); border-radius: 6px; padding: 8px; }
.thumb { width: 100%; height: 110px; border-radius: 4px; }
.name { font-size: 13px; margin-top: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.meta { display: flex; justify-content: space-between; color: var(--el-text-color-secondary); font-size: 12px; margin-top: 2px; }
.ops { display: flex; justify-content: space-between; margin-top: 4px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
