<!-- resources/js/Components/Pagination.vue -->
<template>
  <nav v-if="totalPages > 1" aria-label="Page navigation" class="mt-4">
    <ul class="pagination justify-content-end m-0">

      <!-- Prev button -->
      <li :class="['page-item', { disabled: currentPage === 1 }]">
        <button
          v-if="currentPage > 1"
          @click="navigate(currentPage - 1)"
          class="page-link"
        >&laquo;</button>
        <span v-else class="page-link text-muted">&laquo;</span>
      </li>

      <!-- Page numbers with ellipsis -->
      <template v-for="(item, index) in visiblePages" :key="index">
        <li v-if="item === '...'" class="page-item disabled">
          <span class="page-link text-muted">…</span>
        </li>
        <li v-else :class="['page-item', { active: item === currentPage }]">
          <button @click="navigate(item)" class="page-link">{{ item }}</button>
        </li>
      </template>

      <!-- Next button -->
      <li :class="['page-item', { disabled: currentPage === totalPages }]">
        <button
          v-if="currentPage < totalPages"
          @click="navigate(currentPage + 1)"
          class="page-link"
        >&raquo;</button>
        <span v-else class="page-link text-muted">&raquo;</span>
      </li>

    </ul>
  </nav>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
  links: {
    type: Array,
    required: true,
  },
})

// Extract current page and total pages from Inertia's links array
const currentPage = computed(() => {
  const active = props.links.find(l => l.active)
  return active ? Number(active.label) : 1
})

const totalPages = computed(() => {
  // Last link is "Next »", second-to-last is the last page number
  const pageLinks = props.links.filter(l => !isNaN(Number(l.label)))
  return pageLinks.length ? Number(pageLinks[pageLinks.length - 1].label) : 1
})

const visiblePages = computed(() => {
  const current = currentPage.value
  const total = totalPages.value

  // Always show: 1, current, current+1, total-1, total (filtered to valid range)
  const pinned = new Set(
    [1, current, current + 1, total - 1, total].filter(p => p >= 1 && p <= total)
  )

  const sorted = Array.from(pinned).sort((a, b) => a - b)
  const result = []

  let prev = null
  for (const page of sorted) {
    if (prev !== null && page - prev > 1) result.push('...')
    result.push(page)
    prev = page
  }

  return result
})

function navigate(page) {
  const link = props.links.find(l => Number(l.label) === page)
  if (link?.url) {
    router.get(link.url, {}, { preserveState: true, preserveScroll: true })
  }
}
</script>