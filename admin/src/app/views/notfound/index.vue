<template>
  <el-result icon="warning" title="404" sub-title="页面不存在或无权访问">
    <template #extra>
      <el-button type="primary" @click="$router.push('/dashboard')">返回控制台</el-button>
    </template>
  </el-result>
</template>
