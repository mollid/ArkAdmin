<template>
  <el-card>
    <el-button v-permission="'system.role.store'" type="success" @click="openDialog()">
      {{ t('common.create') }}
    </el-button>
    <el-table :data="rows" border stripe style="margin-top:12px">
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="name" label="角色标识" />
      <el-table-column label="权限数" width="90">
        <template #default="{ row }">{{ row.permissions.length }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.role.update'" link type="primary" :disabled="row.name === 'super_admin'"
            @click="openDialog(row)">{{ t('common.edit') }}</el-button>
          <el-button v-permission="'system.role.destroy'" link type="danger" :disabled="row.name === 'super_admin'"
            @click="remove(row)">{{ t('common.delete') }}</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="560px">
      <el-form :model="dialog.form" label-width="80px">
        <el-form-item label="角色标识"><el-input v-model="dialog.form.name" /></el-form-item>
        <el-form-item label="权限">
          <!-- :key 每次打开对话框强制重建 el-tree——default-checked-keys 变更是"只加勾不取消"的追加语义，
               复次打开会残留上次勾选，重建后初始化语义正确（getCheckedKeys(true) 只取叶子即权限本身） -->
          <el-tree ref="treeRef" :key="treeKey" :data="permTree" show-checkbox node-key="name"
            :props="{ label: 'name', children: 'children' }" default-expand-all
            :default-checked-keys="dialog.form.permissions" style="width:100%" />
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
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import { roleApi, type RoleRow } from '../../../api/admin'

const { t } = useI18n()
const rows = ref<RoleRow[]>([])
const grouped = ref<Record<string, { name: string; module: string }[]>>({})
const treeRef = ref()
const treeKey = ref(0)
const dialog = reactive({ visible: false, form: { id: 0, name: '', permissions: [] as string[] } })

const permTree = computed(() =>
  Object.entries(grouped.value).map(([module, items]) => ({
    name: module,
    children: items.map((i) => ({ name: i.name, module: i.module })),
  })))

async function load() {
  rows.value = await roleApi.list()
}

function openDialog(row?: RoleRow) {
  Object.assign(dialog, {
    visible: true,
    form: row ? { id: row.id, name: row.name, permissions: [...row.permissions] } : { id: 0, name: '', permissions: [] },
  })
  treeKey.value++
}

async function save() {
  const checked = treeRef.value?.getCheckedKeys(true) ?? []
  if (dialog.form.id) {
    await roleApi.update(dialog.form.id, { name: dialog.form.name, permissions: checked })
  } else {
    await roleApi.store({ name: dialog.form.name, permissions: checked })
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: RoleRow) {
  try {
    await ElMessageBox.confirm(`确认删除角色 ${row.name}？`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  await roleApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

onMounted(async () => {
  await load()
  grouped.value = await roleApi.permissions()
})
</script>
