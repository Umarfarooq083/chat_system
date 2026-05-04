<script setup>
import { ref } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    company: Object
})

const copied = ref(false)

const embedCode = `
<script
    src="https://92.204.187.99:8000/chat-widget/embed.js"
    data-chat-url="https://92.204.187.99:8000"
    data-position="right"
    data-title="Chat with us"
    data-color="#111827"
    data-company-id="${props.company.uuid}">
<\/script>`

const copyCode = async () => {
    try {
        await navigator.clipboard.writeText(embedCode)
    } catch (e) {
        const textarea = document.createElement('textarea')
        textarea.value = embedCode
        document.body.appendChild(textarea)
        textarea.select()
        document.execCommand('copy')
        document.body.removeChild(textarea)
    }
    copied.value = true
    setTimeout(() => {
        copied.value = false
    }, 2000)
}
</script>

<template>

    <Head title="Company Details" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Company Details
                </h2>
                <div class="flex gap-2">
                    <Link :href="route('companies.index')"
                        class="inline-flex items-center gap-1.5 px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Back to Companies
                    </Link>
                    <Link v-if="company.name !== 'Default'" :href="route('companies.edit', company.id)"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    Edit Company
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-6 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">UUID</dt>
                                <dd class="mt-1 text-sm text-gray-900 font-mono">{{ company.uuid }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Name</dt>
                                <dd class="mt-1 text-sm text-gray-900">{{ company.name }}</dd>
                            </div>
                            <div class="sm:col-span-2">
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ company.description || '-' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Created At</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ new Date(company.created_at).toLocaleDateString() }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Updated At</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    {{ new Date(company.updated_at).toLocaleDateString() }}
                                </dd>
                            </div>
                            <div v-if="company.deleted_at">
                                <dt class="text-sm font-medium text-red-600">Deleted At</dt>
                                <dd class="mt-1 text-sm text-red-600">
                                    {{ new Date(company.deleted_at).toLocaleDateString() }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>

         <div class="py-12">
            <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                            <pre class="bg-gray-900 text-green-400 p-4 rounded-md text-sm overflow-x-auto">
                            <code>{{ embedCode }}</code>
                            </pre>

                            <button @click="copyCode" class="mt-2 px-3 py-1 bg-indigo-600 text-white rounded">
                                {{ copied ? 'Copied' : 'Copy' }}
                            </button>
                    </div>
                </div>
            </div>
        </div>

    </AuthenticatedLayout>
</template>
