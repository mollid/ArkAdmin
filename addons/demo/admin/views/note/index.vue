<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('demo.note.title') }}</span>
        <el-button v-if="user.has('addon.demo.note.store')" type="primary" @click="dialog = true">
          {{ t('demo.note.create') }}
        </el-button>
      </div>
    </template>

    <el-empty v-if="!rows.length" :description="t('demo.note.empty')" />
    <el-timeline v-else>
      <el-timeline-item v-for="n in rows" :key="n.id" :timestamp="n.created_at">
        {{ n.content }}
        <span class="meta">#{{ n.id }} · {{ t('demo.note.createdBy') }}#{{ n.admin_id }}</span>
      </el-timeline-item>
    </el-timeline>

    <el-dialog v-model="dialog" :title="t('demo.note.create')" width="420px">
      <el-input v-model="content" type="textarea" :rows="3" :placeholder="t('demo.note.contentRequired')" />
      <template #footer>
        <el-button @click="dialog = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :disabled="!content.trim()" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { noteApi, type Note } from '../../api/note'
import { useUserStore } from '@/app/stores/user'

const { t } = useI18n()
const user = useUserStore()
const rows = ref<Note[]>([])
const dialog = ref(false)
const content = ref('')

async function load() {
  rows.value = (await noteApi.list()).list
}

async function save() {
  await noteApi.store({ content: content.value.trim() })
  ElMessage.success(t('common.success'))
  dialog.value = false
  content.value = ''
  load()
}

onMounted(load)
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
.meta { color: var(--el-text-color-secondary); font-size: 12px; margin-left: 8px; }
</style>
