<template>
  <el-card>
    <h2>{{ store.adminInfo?.name }}，欢迎回来</h2>
    <p>ArkAdmin M1 · Laravel 12 + Vue 3 + Element Plus</p>
  </el-card>
</template>

<script setup lang="ts">
import { useUserStore } from '../../stores/user'
const store = useUserStore()
</script>
