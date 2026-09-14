<template>
  <el-card>
    <el-button v-permission="'system.menu.store'" type="success" @click="openDialog()">
      {{ t('common.create') }}
    </el-button>
    <el-table :data="rows" row-key="id" border default-expand-all style="margin-top:12px">
      <el-table-column prop="title" label="标题" min-width="140" />
      <el-table-column prop="name" label="标识" min-width="120" />
      <el-table-column prop="route_path" label="路由" min-width="120" />
      <el-table-column prop="view_path" label="组件" min-width="140" />
      <el-table-column prop="permission" label="权限串" min-width="140" />
      <el-table-column label="显示" width="70">
        <template #default="{ row }">
          <el-tag :type="row.is_show ? 'success' : 'info'">{{ row.is_show ? '是' : '否' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.menu.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'system.menu.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="560px">
      <el-form :model="dialog.form" label-width="90px">
        <el-form-item label="父级菜单">
          <el-tree-select v-model="dialog.form.parent_id" :data="parentOptions" check-strictly
            :props="{ label: 'title', value: 'id' }" node-key="id" style="width:100%" />
        </el-form-item>
        <el-form-item label="标题"><el-input v-model="dialog.form.title" /></el-form-item>
        <el-form-item label="标识"><el-input v-model="dialog.form.name" placeholder="如 system.admin" /></el-form-item>
        <el-form-item label="图标"><el-input v-model="dialog.form.icon" placeholder="Element Plus 图标名" /></el-form-item>
        <el-form-item label="路由路径"><el-input v-model="dialog.form.route_path" placeholder="/system/admin" /></el-form-item>
        <el-form-item label="组件路径"><el-input v-model="dialog.form.view_path" placeholder="目录型留空" /></el-form-item>
        <el-form-item label="权限串"><el-input v-model="dialog.form.permission" /></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="dialog.form.sort" /></el-form-item>
        <el-form-item label="是否显示"><el-switch v-model="dialog.form.is_show" /></el-form-item>
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
import { menuApi } from '../../../api/admin'
import type { MenuItem } from '../../../api/auth'

const { t } = useI18n()
const rows = ref<MenuItem[]>([])
const dialog = reactive({
  visible: false,
  form: {} as Partial<MenuItem> & { parent_id: number },
})

const parentOptions = ref<MenuItem[]>([])

async function load() {
  rows.value = await menuApi.list()
  parentOptions.value = [{ id: 0, parent_id: 0, name: 'root', title: '顶级', icon: '', route_path: '',
    view_path: '', permission: '', addon_key: '', sort: 0, children: rows.value }]
}

function openDialog(row?: MenuItem) {
  Object.assign(dialog, {
    visible: true,
    form: row ? { ...row } : { parent_id: 0, sort: 0, is_show: true } as never,
  })
}

async function save() {
  if (dialog.form.id) {
    await menuApi.update(dialog.form.id, dialog.form)
  } else {
    await menuApi.store(dialog.form)
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: MenuItem) {
  await ElMessageBox.confirm(`确认删除菜单 ${row.title}（含子菜单）？`, '提示', { type: 'warning' })
  await menuApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(load)
</script>
