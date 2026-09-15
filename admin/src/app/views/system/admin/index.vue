<template>
  <el-card>
    <el-form inline>
      <el-form-item label="用户名">
        <el-input v-model="query.username" clearable @keyup.enter="load" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="load">{{ t('common.search') }}</el-button>
      </el-form-item>
      <el-form-item>
        <el-button v-permission="'system.admin.store'" type="success" @click="openDialog()">
          {{ t('common.create') }}
        </el-button>
      </el-form-item>
    </el-form>

    <el-table :data="rows" border stripe>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="username" label="用户名" />
      <el-table-column prop="name" label="姓名" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'danger'">{{ row.status === 1 ? '启用' : '禁用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button v-permission="'system.admin.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'system.admin.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="query.page" :page-size="15" @current-change="load" />

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="480px">
      <el-form :model="dialog.form" label-width="80px">
        <el-form-item label="用户名"><el-input v-model="dialog.form.username" /></el-form-item>
        <el-form-item label="姓名"><el-input v-model="dialog.form.name" /></el-form-item>
        <el-form-item :label="dialog.form.id ? '新密码' : '密码'">
          <el-input v-model="dialog.form.password" type="password" show-password />
        </el-form-item>
        <el-form-item label="角色">
          <el-select v-model="dialog.form.roles" multiple :disabled="!rolesAvailable" style="width:100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="dialog.form.status" :active-value="1" :inactive-value="0" />
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
import { adminApi, roleApi, type AdminRow, type RoleRow } from '../../../api/admin'

const { t } = useI18n()
const rows = ref<AdminRow[]>([])
const roles = ref<RoleRow[]>([])
const total = ref(0)
const query = reactive({ username: '', page: 1 })

const dialog = reactive({
  visible: false,
  form: { id: 0, username: '', name: '', password: '', status: 1, roles: [] as number[] },
})

async function load() {
  const data = await adminApi.list(query)
  rows.value = data.list
  total.value = data.total
}

function openDialog(row?: AdminRow) {
  Object.assign(dialog, {
    visible: true,
    form: row
      ? { id: row.id, username: row.username, name: row.name, password: '', status: row.status, roles: [...row.roles] }
      : { id: 0, username: '', name: '', password: '', status: 1, roles: [] },
  })
}

async function save() {
  if (dialog.form.id) {
    await adminApi.update(dialog.form.id, { ...dialog.form })
  } else {
    await adminApi.store({ ...dialog.form })
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: AdminRow) {
  try {
    await ElMessageBox.confirm(`确认删除管理员 ${row.username}？`, '提示', { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  await adminApi.destroy(row.id)
  ElMessage.success(t('common.success'))
  load()
}

const rolesAvailable = ref(true)

onMounted(async () => {
  await load()
  try {
    roles.value = await roleApi.list()
  } catch {
    // 无 system.role.index 权限：下拉禁用，编辑时保留该账号原有角色
    rolesAvailable.value = false
  }
})
</script>

<style scoped>
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
