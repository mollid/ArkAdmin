<template>
  <!-- 首载骨架：仅当 loading 为 true 时渲染（Q6C 首次加载骨架） -->
  <el-skeleton v-if="loading" :rows="5" animated />
</template>

<script setup lang="ts">
defineProps<{ loading: boolean }>()
</script>
