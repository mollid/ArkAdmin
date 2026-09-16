<template>
  <el-card>
    <template #header>
      <div class="card-header">
        <span>{{ t('cms.article.title') }}</span>
        <el-button v-permission="'addon.cms.article.store'" type="primary" @click="openDialog()">
          {{ t('common.create') }}
        </el-button>
      </div>
    </template>

    <el-form inline>
      <el-form-item :label="t('cms.article.keyword')">
        <el-input v-model="query.keyword" clearable style="width: 180px" @keyup.enter="load(1)" @clear="load(1)" />
      </el-form-item>
      <el-form-item :label="t('cms.article.category')">
        <el-tree-select v-model="query.category_id" :data="categoryOptions" check-strictly
          :props="{ label: 'name', value: 'id' }" node-key="id" clearable style="width: 160px" />
      </el-form-item>
      <el-form-item :label="t('cms.article.status')">
        <el-select v-model="query.status" clearable style="width: 120px">
          <el-option :label="t('cms.article.draft')" :value="0" />
          <el-option :label="t('cms.article.published')" :value="1" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button @click="load(1)">{{ t('common.search') }}</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="rows" border stripe>
      <el-table-column prop="id" label="ID" width="60" />
      <el-table-column prop="title" :label="t('cms.article.titleField')" min-width="180" />
      <el-table-column :label="t('cms.article.category')" width="110">
        <template #default="{ row }">{{ row.category?.name }}</template>
      </el-table-column>
      <el-table-column :label="t('cms.article.tags')" min-width="140">
        <template #default="{ row }">
          <el-tag v-for="tag in row.tags" :key="tag" size="small" style="margin-right: 4px">{{ tag }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('cms.article.cover')" width="80">
        <template #default="{ row }">
          <el-image v-if="row.cover" :src="row.cover_url" fit="cover"
            style="width: 44px; height: 44px; border-radius: 4px" :preview-src-list="[row.cover_url]"
            preview-teleported hide-on-click-modal />
        </template>
      </el-table-column>
      <el-table-column :label="t('cms.article.status')" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">
            {{ row.status === 1 ? t('cms.article.published') : t('cms.article.draft') }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="published_at" :label="t('cms.article.publishedAt')" width="160" />
      <el-table-column :label="t('common.edit')" width="140">
        <template #default="{ row }">
          <el-button v-permission="'addon.cms.article.update'" link type="primary" @click="openDialog(row)">
            {{ t('common.edit') }}
          </el-button>
          <el-button v-permission="'addon.cms.article.destroy'" link type="danger" @click="remove(row)">
            {{ t('common.delete') }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination class="pager" layout="total, prev, pager, next" :total="total"
      v-model:current-page="query.page" :page-size="15" @current-change="load()" />

    <el-dialog v-model="dialog.visible" :title="dialog.form.id ? t('common.edit') : t('common.create')" width="860px">
      <el-form :model="dialog.form" label-width="90px">
        <el-form-item :label="t('cms.article.titleField')">
          <el-input v-model="dialog.form.title" maxlength="191" />
        </el-form-item>
        <el-form-item :label="t('cms.article.category')">
          <el-tree-select v-model="dialog.form.category_id" :data="categoryOptions" check-strictly
            :props="{ label: 'name', value: 'id' }" node-key="id" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('cms.article.summary')">
          <el-input v-model="dialog.form.summary" maxlength="255" />
        </el-form-item>
        <el-form-item :label="t('cms.article.tags')">
          <el-select v-model="dialog.form.tags" multiple filterable allow-create default-first-option
            :placeholder="t('cms.article.tagsPlaceholder')" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('cms.article.cover')">
          <div class="cover">
            <el-image v-if="coverUrl" :src="coverUrl" fit="cover"
              style="width: 80px; height: 80px; border-radius: 4px" />
            <el-button size="small" @click="pickerVisible = true">{{ t('common.choose') }}</el-button>
            <el-button v-if="coverUrl" size="small" text type="danger" @click="clearCover">
              {{ t('cms.article.clearCover') }}
            </el-button>
          </div>
        </el-form-item>
        <el-form-item :label="t('cms.article.status')">
          <el-switch v-model="dialog.form.status" :active-value="1" :inactive-value="0"
            :active-text="t('cms.article.published')" />
        </el-form-item>
        <el-form-item :label="t('cms.article.content')">
          <RichEditor v-model="dialog.form.content" style="width: 100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog.visible = false">{{ t('common.cancel') }}</el-button>
        <el-button type="primary" :disabled="!canSave" @click="save">{{ t('common.confirm') }}</el-button>
      </template>
    </el-dialog>

    <!-- 框架共享素材选择器（§9 素材库消费：封面） -->
    <AttachmentPicker v-model="pickerVisible" @confirm="onPickCover" />
  </el-card>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'
import AttachmentPicker from '@/app/components/AttachmentPicker.vue'
import type { AttachmentRow } from '@/app/api/attachment'
import RichEditor from '../../components/RichEditor.vue'
import { categoryApi, type CategoryNode } from '../../api/category'
import { articleApi, type ArticlePayload, type ArticleRow } from '../../api/article'

const { t } = useI18n()
const rows = ref<ArticleRow[]>([])
const total = ref(0)
const categories = ref<CategoryNode[]>([])
const pickerVisible = ref(false)
const coverUrl = ref('')

const query = reactive<{ keyword: string; category_id?: number; status?: number; page: number }>({
  keyword: '', page: 1,
})

const dialog = reactive({
  visible: false,
  form: { id: 0, category_id: undefined as number | undefined, title: '', summary: '',
    content: '', cover: '', tags: [] as string[], status: 1 },
})

// 树选数据源即栏目树：不注入"未分类"伪节点——后端过滤把 0 当空值、表单提交 0 会被 exists 规则拒绝
const categoryOptions = computed<CategoryNode[]>(() => categories.value)

const canSave = computed(() => !!dialog.form.title.trim() && dialog.form.category_id !== undefined)

async function load(p?: number) {
  if (p) query.page = p
  const data = await articleApi.list({ ...query })
  rows.value = data.list
  total.value = data.total
}

async function openDialog(row?: ArticleRow) {
  if (row) {
    const full = await articleApi.show(row.id)   // 列表不含 content，编辑取全量
    Object.assign(dialog, {
      visible: true,
      form: { id: full.id, category_id: full.category_id, title: full.title, summary: full.summary,
        content: full.content ?? '', cover: full.cover, tags: [...full.tags], status: full.status },
    })
    coverUrl.value = full.cover_url
  } else {
    Object.assign(dialog, {
      visible: true,
      form: { id: 0, category_id: categories.value[0]?.id, title: '', summary: '',
        content: '', cover: '', tags: [], status: 1 },
    })
    coverUrl.value = ''
  }
}

function onPickCover(picked: AttachmentRow[]) {
  const row = picked[0]
  if (!row) return
  dialog.form.cover = row.path      // 存路径（提交值）
  coverUrl.value = row.url          // 展示用绝对地址
}

function clearCover() {
  dialog.form.cover = ''
  coverUrl.value = ''
}

async function save() {
  const payload: ArticlePayload = {
    category_id: dialog.form.category_id!,
    title: dialog.form.title.trim(),
    summary: dialog.form.summary,
    content: dialog.form.content,
    cover: dialog.form.cover,
    tags: dialog.form.tags,
    status: dialog.form.status,
  }
  try {
    if (dialog.form.id) await articleApi.update(dialog.form.id, payload)
    else await articleApi.store(payload)
  } catch {
    return // 参数/业务错误已由拦截器提示
  }
  ElMessage.success(t('common.success'))
  dialog.visible = false
  load()
}

async function remove(row: ArticleRow) {
  try {
    await ElMessageBox.confirm(t('cms.article.deleteConfirm', { title: row.title }), t('common.tip'), { type: 'warning' })
  } catch {
    return // 用户取消确认
  }
  try {
    await articleApi.destroy(row.id)
  } catch {
    return
  }
  ElMessage.success(t('common.success'))
  load()
}

onMounted(async () => {
  await load(1)
  categories.value = await categoryApi.list()
})
</script>

<style scoped>
.card-header { display: flex; align-items: center; justify-content: space-between; }
.cover { display: flex; align-items: center; gap: 10px; }
.pager { margin-top: 12px; justify-content: flex-end; }
</style>
