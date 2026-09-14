<template>
  <el-container class="layout">
    <el-aside width="220px">
      <div class="logo">{{ t('app.title') }}</div>
      <el-menu router :default-active="$route.path" class="menu">
        <menu-item v-for="m in store.menus" :key="m.id" :item="m" />
      </el-menu>
    </el-aside>
    <el-container>
      <el-header class="header">
        <span />
        <el-dropdown @command="onCommand">
          <span class="user">{{ store.adminInfo?.name || store.adminInfo?.username }}</span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <el-main><router-view /></el-main>
    </el-container>
  </el-container>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useUserStore } from '../../stores/user'
import MenuItem from './components/MenuItem.vue'

const { t } = useI18n()
const router = useRouter()
const store = useUserStore()

function onCommand(cmd: string) {
  if (cmd === 'logout') {
    store.logout().then(() => router.push('/login'))
  }
}
</script>

<style scoped src="./layout.scss" lang="scss" />
