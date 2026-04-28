<!-- resources/js/Components/Pagination.vue -->
<template>
  <nav v-if="links?.length > 3" aria-label="Page navigation" class="mt-4">
    <ul class="pagination justify-content-end m-0">
      <li
        v-for="(link, index) in links"
        :key="index"
        :class="['page-item', { active: link.active, disabled: !link.url }]"
      >
        <button
          v-if="link.url"
          @click="router.get(link.url, {}, { preserveState: true, preserveScroll: true })"
          class="page-link"
          v-html="link.label"
        />
        <span
          v-else
          class="page-link text-muted"
          v-html="link.label"
        />
      </li>
    </ul>
  </nav>
</template>

<script setup>
import { router } from '@inertiajs/vue3'

defineProps({
  links: {
    type: Array,
    required: true,
  },
});
</script>
