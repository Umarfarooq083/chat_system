<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ from: '', to: '' }),
    },
});

const fromDate = ref(props.filters.from || '');
const toDate   = ref(props.filters.to   || '');

let debounceTimer = null;

function applyFilters() {
    router.get(
        route('agent.reports'),
        { from: fromDate.value, to: toDate.value },
        { preserveState: true, replace: true }
    );
}

function resetFilters() {
    const today = new Date().toISOString().slice(0, 10);
    fromDate.value = today;
    toDate.value   = today;
    applyFilters();
}
</script>

<template>
    <Head title="System Reports" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analytics & Reports</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Date Filter Bar -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-5">
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">From Date</label>
                            <input
                                type="date"
                                v-model="fromDate"
                                :max="toDate || undefined"
                                class="border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">To Date</label>
                            <input
                                type="date"
                                v-model="toDate"
                                :min="fromDate || undefined"
                                class="border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div class="flex gap-2 pb-0.5">
                            <button
                                @click="applyFilters"
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z" />
                                </svg>
                                Apply Filter
                            </button>
                            <button
                                @click="resetFilters"
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-gray-400"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                                Today
                            </button>
                        </div>

                        <!-- Active filter badge -->
                        <div class="ml-auto flex items-center gap-2 text-sm text-gray-500 pb-0.5">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <span>
                                Showing data for
                                <span class="font-semibold text-gray-700">{{ filters.from }}</span>
                                <template v-if="filters.from !== filters.to">
                                    &nbsp;→&nbsp;<span class="font-semibold text-gray-700">{{ filters.to }}</span>
                                </template>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Total Visits</div>
                        <div class="text-3xl font-bold text-gray-900 mt-2">{{ stats.total_visits }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Users Engaged</div>
                        <div class="text-3xl font-bold text-indigo-600 mt-2">{{ stats.users_who_messaged }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Active Chats</div>
                        <div class="text-3xl font-bold text-green-600 mt-2">{{ stats.active_chats_count }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Unassigned Chats</div>
                        <div class="text-3xl font-bold text-yellow-500 mt-2">{{ stats?.unassigned_chats_count || 0 }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Chats by Status</div>
                        <div class="mt-2 space-y-1">
                            <div v-for="status in stats.chats_by_status" :key="status.status" class="flex justify-between text-sm">
                                <span class="capitalize text-gray-600">{{ status.status }}</span>
                                <span class="font-medium text-gray-900">{{ status.count }}</span>
                            </div>
                            <div v-if="!stats.chats_by_status.length" class="text-sm text-gray-400">No data</div>
                        </div>
                    </div>
                </div>

                <!-- Agent Table -->
                <div class="grid grid-cols-1">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Active Chats by Agent</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Chats Start</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">User Replies</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="concurrency in stats.agent_concurrency" :key="concurrency.assigned_agent_id">
                                        <td class="px-4 py-3 text-sm text-gray-900">{{ concurrency?.name || 'Unknown' }}</td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ concurrency?.agent_sent_users }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                {{ concurrency?.user_replied_users }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="!stats.agent_concurrency.length">
                                        <td colspan="3" class="px-4 py-4 text-center text-sm text-gray-500">No active chats</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
