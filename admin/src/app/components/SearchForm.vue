<template>
  <!-- 列表页筛选区统一布局：enter 即搜、搜索/重置按钮就位；筛选控件由页面以 slot 提供 -->
  <el-form inline @submit.prevent="emit('search')">
    <slot />
    <el-form-item>
      <el-button type="primary" native-type="submit">{{ t('common.search') }}</el-button>
      <el-button @click="emit('reset')">{{ t('common.reset') }}</el-button>
    </el-form-item>
    <slot name="actions" />
  </el-form>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const emit = defineEmits<{ search: []; reset: [] }>()
const { t } = useI18n()
</script>
