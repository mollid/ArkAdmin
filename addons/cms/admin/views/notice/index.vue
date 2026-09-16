<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('cms.notice.title') }}</span>
        <el-button v-permission="'addon.cms.notice.store'" type="primary" @click="openDialog()">
          {{ t('common.create') }}
        </el-button>
      </div>
    </template>

    <el-form inline>
      <el-form-item :label="t('common.search')">
        <el-input v-model="keyword" clearable style="width: 200px" @keyup.enter="load(1)" @clear="load(1)" />
      </el-form-item>
      <el-form-item>
        <el-button @click="load(1)">{{ t('common.search') }}</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="rows" border stripe>
      <el-table-column prop="title" label="公告标题" min-width="120" />
      <el-table-column prop="is_pinned" label="是否置顶" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_pinned ? 'success' : 'info'">{{ row.is_pinned ? '是' : '否' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('common.edit')" width="140">
        <template #default="{ row }">
          <el-button v-permission="'addon.cms.notice.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'addon.cms.notice.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="page" :page-size="15" @current-change="load()" />

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="640px">
      <el-form :model="dialog.form" label-width="110px">
        <el-form-item label="公告标题">
          <el-input v-model="form.title" />
        </el-form-item>
        <el-form-item label="公告内容">
          <el-input type="textarea" :rows="4" v-model="form.body" />
        </el-form-item>
        <el-form-item label="是否置顶">
          <el-switch v-model="form.is_pinned" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { noticeApi, type NoticeRow } from '../../api/notice'

/* ark:crud 生成（表 cms_notices） */

const { t } = useI18n()
const rows = ref<NoticeRow[]>([])
const total = ref(0)
const page = ref(1)
const keyword = ref('')

const dialog = reactive({
  visible: false,
  form: { id: 0, title: '', body: '', is_pinned: false } as Record<string, unknown> & { id: number },
})

async function load(p?: number) {
  if (p) page.value = p
  const data = await noticeApi.list({ page: page.value, keyword: keyword.value })
  rows.value = data.list
  total.value = data.total
}

async function openDialog(row?: NoticeRow) {
  if (row) {
    const full = await noticeApi.show(row.id)   // 列表不含长文本，编辑取全量
    dialog.form = { ...full } as typeof dialog.form
  } else {
    dialog.form = { id: 0, title: '', body: '', is_pinned: false }
  }
  dialog.visible = true
}

async function save() {
  try {
    const { id, ...payload } = dialog.form
    if (id) await noticeApi.update(id, payload)
    else await noticeApi.store(payload)
  } catch {
    return // 参数/业务错误已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: NoticeRow) {
  try {
    await ElMessageBox.confirm(t('common.delete') + ` #${row.id}?`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  try {
    await noticeApi.destroy(row.id)
  } catch {
    return
  }
  ElMessage.success(t('common.success'))
  load()
}

onMounted(() => load(1))
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
