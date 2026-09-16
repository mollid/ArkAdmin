<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('fy.post.title') }}</span>
        <el-button v-permission="'addon.fy.post.store'" type="primary" @click="openDialog()">
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
      <el-table-column prop="title" label="Title" min-width="120" />
      <el-table-column :label="t('common.edit')" width="140">
        <template #default="{ row }">
          <el-button v-permission="'addon.fy.post.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'addon.fy.post.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="page" :page-size="15" @current-change="load()" />

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="640px">
      <el-form :model="dialog.form" label-width="110px">
        <el-form-item label="Title">
          <el-input v-model="form.title" />
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
import { postApi, type PostRow } from '../../api/post'

/* ark:crud 生成（表 fy_posts） */

const { t } = useI18n()
const rows = ref<PostRow[]>([])
const total = ref(0)
const page = ref(1)
const keyword = ref('')

const dialog = reactive({
  visible: false,
  form: { id: 0, title: '' } as Record<string, unknown> & { id: number },
})

async function load(p?: number) {
  if (p) page.value = p
  const data = await postApi.list({ page: page.value, keyword: keyword.value })
  rows.value = data.list
  total.value = data.total
}

async function openDialog(row?: PostRow) {
  if (row) {
    const full = await postApi.show(row.id)   // 列表不含长文本，编辑取全量
    dialog.form = { ...full } as typeof dialog.form
  } else {
    dialog.form = { id: 0, title: '' }
  }
  dialog.visible = true
}

async function save() {
  try {
    const { id, ...payload } = dialog.form
    if (id) await postApi.update(id, payload)
    else await postApi.store(payload)
  } catch {
    return // 参数/业务错误已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: PostRow) {
  try {
    await ElMessageBox.confirm(t('common.delete') + ` #${row.id}?`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  try {
    await postApi.destroy(row.id)
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
