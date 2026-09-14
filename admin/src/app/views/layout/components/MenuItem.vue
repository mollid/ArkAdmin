<template>
  <el-sub-menu v-if="item.children?.length" :index="item.route_path || `/${item.name}`">
    <template #title>
      <el-icon v-if="item.icon"><component :is="iconComp" /></el-icon>
      <span>{{ item.title }}</span>
    </template>
    <menu-item v-for="c in item.children" :key="c.id" :item="c" />
  </el-sub-menu>
  <el-menu-item v-else :index="item.route_path || `/${item.name}`">
    <el-icon v-if="item.icon"><component :is="iconComp" /></el-icon>
    <span>{{ item.title }}</span>
  </el-menu-item>
</template>

<script setup lang="ts">
import { computed, type Component } from 'vue'
import * as Icons from '@element-plus/icons-vue'
import type { MenuItem } from '../../../api/auth'

defineOptions({ name: 'MenuItem' })

const props = defineProps<{ item: MenuItem }>()
const iconComp = computed<Component | null>(() =>
  (Icons as Record<string, Component>)[props.item.icon] ?? null,
)
</script>
