<template>
  <div class="rich-editor">
    <div ref="host"></div>
  </div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Quill from 'quill'
import 'quill/dist/quill.snow.css'
import { attachmentApi } from '@/app/api/attachment'

const props = withDefaults(defineProps<{ modelValue?: string; placeholder?: string }>(), {
  modelValue: '',
  placeholder: '开始编写正文…',
})
const emit = defineEmits<{ (e: 'update:modelValue', v: string): void }>()

const host = ref<HTMLElement>()
let quill: Quill | null = null
let silent = false

onMounted(() => {
  quill = new Quill(host.value!, {
    theme: 'snow',
    placeholder: props.placeholder,
    modules: {
      toolbar: {
        container: [
          [{ header: [1, 2, 3, false] }],
          ['bold', 'italic', 'underline', 'strike'],
          [{ list: 'ordered' }, { list: 'bullet' }],
          ['blockquote', 'code-block'],
          ['link', 'image'],
          ['clean'],
        ],
        handlers: {
          // 正文插图走框架素材库上传（§9 素材库消费）
          image: () => pickImage(),
        },
      },
    },
  })
  quill.root.innerHTML = props.modelValue
  quill.on('text-change', () => {
    if (silent) return
    emit('update:modelValue', quill!.root.innerHTML)
  })
})

watch(() => props.modelValue, (v) => {
  if (quill && v !== quill.root.innerHTML) {
    silent = true
    quill.root.innerHTML = v
    silent = false
  }
})

/** 文件选择 → 框架素材库上传 → 以返回 url 插入光标处 */
function pickImage() {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.onchange = async () => {
    const file = input.files?.[0]
    if (!file || !quill) return
    try {
      const att = await attachmentApi.upload(file)
      const range = quill.getSelection(true)
      quill.insertEmbed(range?.index ?? 0, 'image', att.url, 'user')
    } catch {
      // 上传失败拦截器已提示
    }
  }
  input.click()
}

onBeforeUnmount(() => {
  quill = null
})
</script>

<style scoped>
.rich-editor :deep(.ql-editor) { min-height: 220px; }
.rich-editor :deep(.ql-toolbar) { border-radius: 4px 4px 0 0; }
.rich-editor :deep(.ql-container) { border-radius: 0 0 4px 4px; }
</style>
