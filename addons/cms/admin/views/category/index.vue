<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('cms.category.title') }}</span>
        <el-button v-permission="'addon.cms.category.store'" type="primary" @click="openDialog()">
          {{ t('common.create') }}
        </el-button>
      </div>
    </template>

    <el-table :data="rows" row-key="id" border default-expand-all>
      <el-table-column prop="name" :label="t('cms.category.name')" min-width="160" />
      <el-table-column prop="description" :label="t('cms.category.description')" min-width="160" />
      <el-table-column prop="sort" :label="t('cms.category.sort')" width="70" />
      <el-table-column prop="article_count" :label="t('cms.category.articleCount')" width="90" />
      <el-table-column :label="t('cms.category.show')" width="80">
        <template #default="{ row }">
          <el-tag :type="row.is_show ? 'success' : 'info'">{{ row.is_show ? '是' : '否' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('common.edit')" width="160">
        <template #default="{ row }">
          <el-button v-permission="'addon.cms.category.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'addon.cms.category.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="520px">
      <el-form :model="dialog.form" label-width="90px">
        <el-form-item :label="t('cms.category.parent')">
          <el-tree-select v-model="dialog.form.parent_id" :data="parentOptions" check-strictly
            :props="{ label: 'name', value: 'id' }" node-key="id" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('cms.category.name')">
          <el-input v-model="dialog.form.name" />
        </el-form-item>
        <el-form-item :label="t('cms.category.description')">
          <el-input v-model="dialog.form.description" />
        </el-form-item>
        <el-form-item :label="t('cms.category.sort')">
          <el-input-number v-model="dialog.form.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('cms.category.show')">
          <el-switch v-model="dialog.form.is_show" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :disabled="!dialog.form.name?.trim()" @click="save">
          {{ t('common.confirm') }}
        </el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { categoryApi, type CategoryNode, type CategoryPayload } from '../../api/category'

const { t } = useI18n()
const rows = ref<CategoryNode[]>([])
const dialog = reactive({
  visible: false,
  form: { id: 0, parent_id: 0, name: '', description: '', sort: 0, is_show: true },
})

/** 顶级 + 全树（排除自己及子孙、父级必须存在，均由后端守卫兜底） */
const parentOptions = computed<CategoryNode[]>(() => [
  { id: 0, parent_id: 0, name: t('cms.category.topLevel'), description: '', sort: 0, is_show: true,
    article_count: 0, children: rows.value },
])

async function load() {
  try {
    rows.value = await categoryApi.list()
  } catch {
    // 拦截器已提示；保持现有数据，避免 unhandled rejection
  }
}

function openDialog(row?: CategoryNode) {
  Object.assign(dialog, {
    visible: true,
    form: row
      ? { id: row.id, parent_id: row.parent_id, name: row.name, description: row.description, sort: row.sort, is_show: row.is_show }
      : { id: 0, parent_id: 0, name: '', description: '', sort: 0, is_show: true },
  })
}

async function save() {
  const payload: CategoryPayload = {
    parent_id: dialog.form.parent_id,
    name: dialog.form.name.trim(),
    description: dialog.form.description,
    sort: dialog.form.sort,
    is_show: dialog.form.is_show,
  }
  try {
    if (dialog.form.id) await categoryApi.update(dialog.form.id, payload)
    else await categoryApi.store(payload)
  } catch {
    return // 业务守卫/参数错误已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: CategoryNode) {
  try {
    await ElMessageBox.confirm(t('cms.category.deleteConfirm', { name: row.name }), t('common.tip'), { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  try {
    await categoryApi.destroy(row.id)
  } catch {
    return // 删除守卫已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  load()
}

onMounted(load)
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
</style>
