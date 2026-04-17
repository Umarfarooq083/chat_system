<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Head title="System Reports" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Analytics & Reports</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Total Visits</div>
                        <div class="text-3xl font-bold text-gray-900 mt-2">{{ stats.total_visits }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Users Who Messaged</div>
                        <div class="text-3xl font-bold text-indigo-600 mt-2">{{ stats.users_who_messaged }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Active Chats</div>
                        <div class="text-3xl font-bold text-green-600 mt-2">{{ stats.active_chats_count }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Active Chats</div>
                        <div class="text-3xl font-bold text-green-600 mt-2">{{ stats?.unassigned_chats_count || 0 }}</div>
                    </div>
                    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm font-medium text-gray-500">Chats by Status</div>
                        <div class="mt-2 space-y-1">
                            <div v-for="status in stats.chats_by_status" :key="status.status" class="flex justify-between text-sm">
                                <span class="capitalize text-gray-600">{{ status.status }}</span>
                                <span class="font-medium text-gray-900">{{ status.count }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 ">
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
                                        <td colspan="2" class="px-4 py-4 text-center text-sm text-gray-500">No active chats</td>
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
