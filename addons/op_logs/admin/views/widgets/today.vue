<template>
  <el-card shadow="never" class="widget">
    <template #header><span>{{ t('op_logs.widget.title') }}</span></template>
    <div class="stats">
      <div class="stat">
        <div class="num">{{ data?.operations ?? '-' }}</div>
        <div class="label">{{ t('op_logs.widget.operations') }}</div>
      </div>
      <div class="stat">
        <div class="num">{{ data?.logins ?? '-' }}</div>
        <div class="label">{{ t('op_logs.widget.logins') }}</div>
      </div>
    </div>
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { opLogApi } from '../../api/opLog'

const { t } = useI18n()
const data = ref<{ operations: number; logins: number } | null>(null)

onMounted(async () => {
  try {
    data.value = await opLogApi.today()
  } catch {
    // 无权限/接口异常：卡片保持占位
  }
})
</script>

<style scoped>
.stats { display: flex; gap: 24px; }
.stat { text-align: center; }
.num { font-size: 24px; font-weight: 600; }
.label { font-size: 12px; color: var(--el-text-color-secondary); margin-top: 4px; }
</style>
