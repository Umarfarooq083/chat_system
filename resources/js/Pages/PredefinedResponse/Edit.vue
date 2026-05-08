<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const props = defineProps({
    response: {
        type: Object,
        required: true,
    },
});

const form = ref({
    title: props.response.title,
    response: props.response.response,
    is_active: props.response.is_active,
    sort_order: props.response.sort_order,
});

const errors = ref({});
const submitting = ref(false);

function submit() {
    submitting.value = true;
    errors.value = {};

    router.put(route('predefined-responses.update', props.response.id), form.value, {
        preserveScroll: true,
        onSuccess: () => {
            // Success handling
        },
        onError: (err) => {
            errors.value = err;
            submitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Edit Predefined Response" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Predefined Response
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- Title Field -->
                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700">Title *</label>
                                <input
                                    type="text"
                                    id="title"
                                    v-model="form.title"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.title ? 'border-red-300' : ''"
                                />
                                <p v-if="errors.title" class="mt-1 text-sm text-red-600">{{ errors.title }}</p>
                            </div>

                            <!-- Response Field -->
                            <div>
                                <label for="response" class="block text-sm font-medium text-gray-700">Response *</label>
                                <textarea
                                    id="response"
                                    v-model="form.response"
                                    rows="6"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.response ? 'border-red-300' : ''"
                                ></textarea>
                                <p v-if="errors.response" class="mt-1 text-sm text-red-600">{{ errors.response }}</p>
                            </div>

                            <!-- Is Active Field -->
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    id="is_active"
                                    v-model="form.is_active"
                                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <label for="is_active" class="ml-2 block text-sm text-gray-700">
                                    Active
                                </label>
                            </div>

                            <!-- Sort Order Field -->
                            <div>
                                <label for="sort_order" class="block text-sm font-medium text-gray-700">Sort Order</label>
                                <input
                                    type="number"
                                    id="sort_order"
                                    v-model="form.sort_order"
                                    min="0"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.sort_order ? 'border-red-300' : ''"
                                />
                                <p class="mt-1 text-xs text-gray-500">Lower numbers appear first</p>
                                <p v-if="errors.sort_order" class="mt-1 text-sm text-red-600">{{ errors.sort_order }}</p>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-4">
                                <Link
                                    :href="route('predefined-responses.index')"
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    Cancel
                                </Link>
                                <button
                                    type="submit"
                                    :disabled="submitting"
                                    class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50"
                                >
                                    <svg v-if="submitting" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ submitting ? 'Saving...' : 'Save Changes' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
