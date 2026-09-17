<template>
  <el-card>
    <template #header>
      <span>{{ t('op_logs.log.title') }}</span>
    </template>

    <el-form inline>
      <el-form-item :label="t('op_logs.log.route')">
        <el-input v-model="keyword" clearable style="width: 220px" @keyup.enter="load(1)" @clear="load(1)" />
      </el-form-item>
      <el-form-item>
        <el-button @click="load(1)">{{ t('common.search') }}</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="rows" border stripe>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column :label="t('op_logs.log.admin')" width="120">
        <template #default="{ row }">{{ row.admin?.username ?? t('op_logs.log.unauthenticated') }}</template>
      </el-table-column>
      <el-table-column prop="method" :label="t('op_logs.log.method')" width="90" />
      <el-table-column prop="route" :label="t('op_logs.log.route')" min-width="220" />
      <el-table-column prop="status_code" :label="t('op_logs.log.status')" width="90" />
      <el-table-column prop="ip" :label="t('op_logs.log.ip')" width="130" />
      <el-table-column prop="created_at" :label="t('op_logs.log.time')" width="170" />
      <el-table-column type="expand">
        <template #default="{ row }">
          <pre class="params">{{ JSON.stringify(row.params, null, 2) }}</pre>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="page" :page-size="15" @current-change="load()" />
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { opLogApi, type OpLogRow } from '../../api/opLog'

const { t } = useI18n()
const rows = ref<OpLogRow[]>([])
const total = ref(0)
const page = ref(1)
const keyword = ref('')

async function load(p?: number) {
  if (p) page.value = p
  const data = await opLogApi.list({ page: page.value, keyword: keyword.value })
  rows.value = data.list
  total.value = data.total
}

onMounted(() => load(1))
</script>

<style scoped>
.params { margin: 0; font-size: 12px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
