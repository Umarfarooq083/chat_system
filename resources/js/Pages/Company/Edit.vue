<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const props = defineProps({
    company: {
        type: Object,
        required: true,
    },
});

const form = ref({
    name: props.company.name,
    description: props.company.description || '',
});

const errors = ref({});
const submitting = ref(false);

function submit() {
    submitting.value = true;
    errors.value = {};

    router.put(route('companies.update', props.company.id), form.value, {
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
    <Head title="Edit Company" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit Company
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <!-- UUID Display -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700">UUID</label>
                                <div class="mt-1 block w-full bg-gray-100 px-3 py-2 rounded-md text-sm text-gray-500 font-mono">
                                    {{ company.uuid }}
                                </div>
                                <p class="mt-1 text-xs text-gray-500">UUID cannot be changed</p>
                            </div>

                            <!-- Name Field -->
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Company Name *</label>
                                <input
                                    type="text"
                                    id="name"
                                    v-model="form.name"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.name ? 'border-red-300' : ''"
                                />
                                <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
                            </div>

                            <!-- Description Field -->
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                                <textarea
                                    id="description"
                                    v-model="form.description"
                                    rows="4"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.description ? 'border-red-300' : ''"
                                ></textarea>
                                <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-4">
                                <Link
                                    :href="route('companies.index')"
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
