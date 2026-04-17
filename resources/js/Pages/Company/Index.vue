<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    companies: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ search: '', trashed: null }),
    },
});

const search = ref(props.filters.search || '');
const trashedFilter = ref(props.filters.trashed || '');

function confirmDestroy(company) {
    if (confirm(`Are you sure you want to delete "${company.name}"?`)) {
        router.delete(route('companies.destroy', company.id), {
            preserveScroll: true,
        });
    }
}

</script>

<template>
    <Head title="Companies" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Companies
                </h2>
                
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
                    <div class="flex gap-2">
                    </div>
                    <Link
                        v-if="trashedFilter !== 'only'"
                        :href="route('companies.create')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Company
                    </Link>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UUID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="company in companies.data" :key="company.id" >
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                                        {{ company.uuid }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-400">
                                        {{ company.name }}
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                        {{ company.description || '-' }}
                                    </td>
                                   
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end gap-2">
                                                <Link
                                                    :href="route('companies.show', company.id)"
                                                    class="inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-gray-700 bg-gray-100 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500"
                                                >
                                                    View
                                                </Link>
                                                <Link
                                                v-if="company.name !== 'Default'"
                                                    :href="route('companies.edit', company.id)"
                                                    class="inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-indigo-700 bg-indigo-100 hover:bg-indigo-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    v-if="company.name !== 'Default'"
                                                    @click="confirmDestroy(company)"
                                                    class="inline-flex items-center px-2.5 py-1.5 border border-transparent text-xs font-medium rounded text-red-700 bg-red-100 hover:bg-red-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500" >
                                                    Delete
                                                </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!companies.data.length">
                                    <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">
                                        <template v-if="trashedFilter === 'only'">
                                            No deleted companies found.
                                        </template>
                                        <template v-else-if="search">
                                            No companies match your search.
                                        </template>
                                        <template v-else>
                                            No companies found. <Link :href="route('companies.create')" class="text-indigo-600 hover:underline">Create one</Link>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="mt-6" v-if="companies.data.length">
                    <div class="flex items-center justify-between text-sm text-gray-600">
                        <span>
                            Showing {{ companies.from }} to {{ companies.to }} of {{ companies.total }} results
                        </span>
                        <div class="flex gap-2">
                            <button
                                v-for="link in companies.links"
                                :key="link.label"
                                :disabled="!link.url"
                                @click="router.get(link.url, {}, { preserveState: true })"
                                v-html="link.label"
                                :class="[
                                    'px-3 py-1 rounded-md',
                                    link.active ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 hover:bg-gray-50',
                                    !link.url && 'opacity-50 cursor-not-allowed'
                                ]"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
