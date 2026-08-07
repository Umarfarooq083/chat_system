<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
const page = usePage();
const isChatPage = page.url.startsWith('/chat-history');
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-slate-100 via-gray-100 to-slate-200">
        <!-- Logo -->
        <div class="pt-8 text-center" >
            <Link href="/" v-if="!isChatPage" >
                <ApplicationLogo style="margin-top: 150px;"
                    class="mx-auto h-20 w-20 text-indigo-600"
                />
            </Link>
        </div>
        <!-- Card -->
        <div  :class="[ 'mx-auto mt-8 w-full rounded-2xl bg-white shadow-xl', isChatPage ? 'chat-history' : 'login' ]">
            <div
                v-if="$slots.header"
                class="border-b bg-gray-50 px-8 py-5 rounded-t-2xl">
                <slot name="header" />
            </div>
            <div class="p-8">
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
    .chat-history {
        max-width: 84rem;
    }
    .login {
        width: 650px;
    }
</style>