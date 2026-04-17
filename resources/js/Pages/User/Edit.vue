<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    user: {
        type: Object,
        required: true,
    },
    companies: {
        type: Array,
        required: true,
    },
});

const form = ref({
    name: props.user.name,
    email: props.user.email,
    password: '',
    company_ids: props.user.companies ? props.user.companies.map(c => c.id) : [],
});

const errors = ref({});
const submitting = ref(false);

function submit() {
    submitting.value = true;
    errors.value = {};

    router.patch(route('users.update', props.user.id), form.value, {
        preserveScroll: true,
        onError: (err) => {
            errors.value = err;
            submitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Edit User" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Edit User
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <form @submit.prevent="submit" class="space-y-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Name *</label>
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

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email *</label>
                                <input
                                    type="email"
                                    id="email"
                                    v-model="form.email"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.email ? 'border-red-300' : ''"
                                />
                                <p v-if="errors.email" class="mt-1 text-sm text-red-600">{{ errors.email }}</p>
                            </div>

                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">
                                    Password <span class="text-gray-500 text-xs">(leave blank to keep current)</span>
                                </label>
                                <input
                                    type="password"
                                    id="password"
                                    v-model="form.password"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                    :class="errors.password ? 'border-red-300' : ''"
                                />
                                <p v-if="errors.password" class="mt-1 text-sm text-red-600">{{ errors.password }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Assign Companies</label>
                                <div class="space-y-2">
                                    <div
                                        v-for="company in companies"
                                        :key="company.id"
                                        class="flex items-center"
                                    >
                                        <input
                                            type="checkbox"
                                            :id="'company-' + company.id"
                                            :value="company.id"
                                            v-model="form.company_ids"
                                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <label :for="'company-' + company.id" class="ml-2 text-sm text-gray-700 flex items-center gap-2">
                                            <span
                                                class="w-3 h-3 rounded-full inline-block"
                                                :style="{ backgroundColor: company.color }"
                                            ></span>
                                            {{ company.name }}
                                        </label>
                                    </div>
                                </div>
                                <p v-if="errors.company_ids" class="mt-1 text-sm text-red-600">{{ errors.company_ids }}</p>
                            </div>

                            <div class="flex items-center justify-end gap-4">
                                <Link
                                    :href="route('users.index')"
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