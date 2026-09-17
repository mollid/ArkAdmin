<template>
  <div class="dashboard">
    <component :is="comp(w)" v-for="w in widgets" :key="w.key" />
    <el-card v-if="loaded && !widgets.length" shadow="never">
      <h2>{{ store.adminInfo?.name }}，欢迎回来</h2>
      <p>当前没有可用的仪表盘卡片：安装插件即可在此贡献卡片（widget 扩展点）。</p>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useUserStore } from '../../stores/user'
import { widgetApi, type WidgetItem } from '../../api/widget'
import { widgetComponentKey } from '../../utils/widget'

// harness 规格 §3.1：仪表盘 = widget 消费壳；卡片由各启用插件贡献、按权限过滤
const store = useUserStore()
const widgets = ref<WidgetItem[]>([])
const loaded = ref(false)

// 插件前端卡片组件（安装时复制进 /src/addons/<key>/views/widgets/）
const globs = import.meta.glob('/src/addons/*/views/widgets/*.vue')

function comp(w: WidgetItem) {
  return globs[widgetComponentKey(w.addon, w.component)] ?? null
}

onMounted(async () => {
  try {
    widgets.value = await widgetApi.list()
  } catch {
    widgets.value = []
  } finally {
    loaded.value = true
  }
})
</script>

<style scoped>
.dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 12px; }
</style>
