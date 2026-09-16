<template>
  <el-dialog :model-value="modelValue" :title="t('common.choose')" width="720px"
    @update:model-value="onVisibleChange" @open="load(1)">
    <div class="toolbar">
      <el-input v-model="keyword" clearable :placeholder="t('common.search')" style="width: 200px"
        @keyup.enter="load(1)" @clear="load(1)" />
      <el-button @click="load(1)">{{ t('common.search') }}</el-button>
      <el-upload v-permission="'system.attachment.store'" :show-file-list="false" :http-request="doUpload" multiple
        accept="image/*" style="margin-left: auto">
        <el-button type="primary">{{ t('common.upload') }}</el-button>
      </el-upload>
    </div>

    <el-empty v-if="!rows.length" />
    <div v-else class="grid">
      <div v-for="row in rows" :key="row.id" class="cell" :class="{ picked: picked.has(row.id) }"
        @click="toggle(row)">
        <el-image :src="row.url" fit="cover" class="thumb"
          :preview-src-list="[row.url]" preview-teleported hide-on-click-modal @click.stop />
        <div class="name" :title="row.name">{{ row.name }}</div>
        <el-checkbox v-if="multiple" :model-value="picked.has(row.id)" @click.stop
          @change="toggle(row)" />
      </div>
    </div>

    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="page" :page-size="15" @current-change="load()" />

    <template #footer>
      <el-button @click="emit('update:modelValue', false)">{{ t('common.cancel') }}</el-button>
      <el-button v-if="multiple" type="primary" :disabled="!picked.size" @click="confirmMulti">
        {{ t('common.confirm') }}
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import { attachmentApi, type AttachmentRow } from '../api/attachment'

const props = defineProps<{ modelValue: boolean; multiple?: boolean }>()
const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
  (e: 'confirm', rows: AttachmentRow[]): void
}>()

const { t } = useI18n()
const rows = ref<AttachmentRow[]>([])
const total = ref(0)
const page = ref(1)
const keyword = ref('')
// 跨页累积已选（key=id）：多选翻页不丢，确认时按累积集合输出
const picked = reactive(new Map<number, AttachmentRow>())

async function load(p?: number) {
  if (p) page.value = p
  try {
    const data = await attachmentApi.list({ page: page.value, keyword: keyword.value, type: 'image' })
    rows.value = data.list
    total.value = data.total
  } catch {
    // 拦截器已提示；打开对话框/翻页失败不应抛 unhandled rejection
  }
}

function onVisibleChange(v: boolean) {
  emit('update:modelValue', v)
  if (!v) picked.clear() // 关闭即清空，避免下次打开残留勾选
}

function toggle(row: AttachmentRow) {
  if (!props.multiple) {
    emit('confirm', [row])
    emit('update:modelValue', false)
    return
  }
  picked.has(row.id) ? picked.delete(row.id) : picked.set(row.id, row)
}

function confirmMulti() {
  emit('confirm', [...picked.values()])
  picked.clear()
  emit('update:modelValue', false)
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
</script>

<style scoped>
.toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.cell { position: relative; border: 2px solid var(--el-border-color-lighter); border-radius: 6px;
  padding: 6px; cursor: pointer; text-align: center; }
.cell.picked { border-color: var(--el-color-primary); }
.thumb { width: 100%; height: 90px; border-radius: 4px; }
.name { font-size: 12px; margin-top: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cell .el-checkbox { position: absolute; top: 4px; right: 6px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
