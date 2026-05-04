<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import Pagination from '@/Components/Pagination.vue';
import { ref } from 'vue';

const props = defineProps({
    stats: {
        type: Object,
        required: true,
    },
    company: {
        type: Object,
        required: true,
    },
    filters: {
        type: Object,
        default: () => ({ from: '', to: '' , selectedCompany: ''}),
    },
});

const fromDate = ref(props.filters.from || '');
const toDate   = ref(props.filters.to   || '');
const selectedCompany = ref(props.filters.selectedCompany || '');

function applyFilters() {
    router.get(
        route('agent.sla-report'),
        { from: fromDate.value, to: toDate.value, selectedCompany: selectedCompany.value },
        { preserveState: true, replace: true }
    );
}

function resetFilters() {
    const today = new Date().toISOString().slice(0, 10);
    fromDate.value = today;
    toDate.value   = today;
    selectedCompany.value = '';
    applyFilters();
}

function formatDateTime(isoString) {
    if (!isoString) return '-';
    const d = new Date(isoString);
    return d.toLocaleString();
}

function formatDuration(seconds) {
    if (!seconds && seconds !== 0) return '-';
    const hrs = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    if (hrs > 0) {
        return `${hrs}h ${mins}m ${secs}s`;
    }
    return `${mins}m ${secs}s`;
}
</script>

<template>
    <Head title="SLA Report" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">SLA Report</h2>
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
                        <div class="flex flex-col gap-1">
                            <label class="text-xs font-medium text-gray-500 uppercase tracking-wide">Company</label>
                            <select v-model="selectedCompany" class="border border-gray-300 rounded-md px-3 py-2 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="" class="text-sm text-gray-700">All Companies</option>
                                <option v-for="comp in company" :key="comp.uuid" :value="comp.uuid" class="text-sm text-gray-700">
                                    {{ comp.name }}
                                </option>
                            </select>
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
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Average Response Time</div>
                        <div class="text-3xl font-bold text-indigo-600 mt-2">{{ stats.avg_response_time }}m</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Total Chats</div>
                        <div class="text-3xl font-bold text-gray-900 mt-2">{{ stats.total_chats }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Chats with Agent Response</div>
                        <div class="text-3xl font-bold text-green-600 mt-2">{{ stats.chats_with_response }}</div>
                    </div>
                </div>
                
                 <!-- Unanswered Chats Table -->
                <div v-if="stats.unanswered_chats" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-red-100">
                        <h3 class="text-lg font-semibold text-red-600">
                            Unanswered Chats ({{ stats.unanswered_chats.total }})
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-red-50 text-red-700 font-medium">
                                <tr>
                                    <th class="px-6 py-3">Chat ID</th>
                                    <th class="px-6 py-3">First Message</th>
                                    <th class="px-6 py-3">Created At</th>
                                </tr>
                            </thead>
                            <!-- {{ stats.unanswered_chats.data }} -->
                            <tbody class="divide-y divide-red-100">
                                <tr v-for="chat in stats.unanswered_chats.data" :key="chat.chat_id">
                                    <td class="px-6 py-4 text-gray-900">{{ chat.chat_id }}</td>
                                    <!-- <td class="px-6 py-4 text-gray-600 truncate max-w-xs" v-if="typeof(JSON.parse(chat?.first_message)) === 'string'">{{ JSON.parse(chat?.first_message)?.name }}</td> -->
                                    <td class="px-6 py-4 text-gray-600 truncate max-w-xs" >{{ chat?.first_message?.name ?? 'N/A' }}</td>
                                   
                                    <td class="px-6 py-4 text-gray-600">{{ formatDateTime(chat.created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                          <Pagination :links="stats.unanswered_chats.links" />
                    </div>
                </div>
                <div v-else class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 text-center text-gray-500">
                    All chats received an agent response.
                </div>
                <!-- Delayed Chats Table -->
                <div v-if="stats.delayed_chats" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">
                            Chats with  delayed response 
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-gray-50 text-gray-700 font-medium">
                                <tr>
                                    <th class="px-6 py-3">Chat ID</th>
                                    <th class="px-6 py-3">Agent Name</th>
                                    <th class="px-6 py-3">Customer Name</th>
                                    <th class="px-6 py-3">Response Time</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                
                                <tr v-for="chat in stats.delayed_chats.data" :key="chat.chat_id">
                                    <td class="px-6 py-4 text-gray-900">{{ chat.chat_id }}</td>
                                    <td class="px-6 py-4 text-gray-600 truncate max-w-xs">{{ chat.agent_name }}</td>
                                    <td class="px-6 py-4 text-gray-600 truncate max-w-xs">{{ chat.customer_name }}</td>
                                    <td class="px-6 py-4">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                            {{ formatDuration(chat.response_time_seconds) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <Pagination :links="stats.delayed_chats.links" />
                </div>
                
                <div v-else class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 text-center text-gray-500">
                    No delayed chats found for the selected period.
                </div>

               

            </div>
        </div>
    </AuthenticatedLayout>
</template>
