<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
  canLogin: Boolean,
  canRegister: Boolean,
});

</script>

<template>
  <Head title="Welcome" />

  <div class="min-h-screen bg-gradient-to-br from-gray-900 via-purple-900 to-gray-900 text-white relative overflow-hidden">

    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute -top-40 -right-40 w-80 h-80 bg-purple-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse"></div>
      <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-pink-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse delay-1000"></div>
      <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-80 h-80 bg-blue-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse delay-2000"></div>
    </div>

    <header class="relative z-10 flex items-center justify-between px-6 py-5 max-w-7xl mx-auto">
      <div class="flex items-center gap-2">
        <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center shadow-lg">
          <span class="text-2xl">💬</span>
        </div>
        <h1 class="text-2xl font-bold bg-gradient-to-r from-white to-purple-200 bg-clip-text text-transparent">
          ChatSystem
        </h1>
      </div>

      <nav v-if="canLogin" class="flex items-center gap-4">
        <Link
          v-if="$page.props.auth.user"
          :href="route('dashboard')"
          class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 text-white font-medium hover:from-purple-500 hover:to-pink-500 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
        >
          Dashboard
        </Link>

        <template v-else>
          <Link
            :href="route('login')"
            class="px-5 py-2.5 rounded-xl border-2 border-purple-400/50 text-white font-medium hover:bg-purple-500/20 hover:border-purple-400 transition-all duration-300"
          >
            Login
          </Link>

          <Link
            v-if="canRegister"
            :href="route('register')"
            class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-pink-600 text-white font-medium hover:from-purple-500 hover:to-pink-500 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
          >
            Get Started
          </Link>
        </template>
      </nav>
    </header>

    <section class="relative z-10 text-center px-6 py-32 max-w-5xl mx-auto">
      
      <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 mb-8">
        <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
        <span class="text-sm font-medium">✨ Real-time messaging platform</span>
      </div>

      <h2 class="text-5xl md:text-7xl font-extrabold leading-tight">
        <span class="bg-gradient-to-r from-purple-400 via-pink-400 to-purple-400 bg-clip-text text-transparent animate-gradient">
          Smart Chat
        </span>
        <br />
        <span class="text-white">for Modern Businesses</span>
      </h2>

      <p class="mt-6 text-xl text-gray-300 max-w-2xl mx-auto">
        Real-time chat system with widget integration. Add to any website and manage conversations effortlessly.
      </p>

      <div class="mt-10 flex flex-wrap justify-center gap-4">
        <Link
          :href="route('login')"
          class="px-8 py-4 border-2 border-purple-400/50 text-white rounded-xl text-lg font-semibold hover:bg-purple-500/20 hover:border-purple-400 transition-all duration-300"
        >
          Sign In
        </Link>
      </div>

    </section>

    <section class="relative z-10 max-w-7xl mx-auto px-6 pb-32">
      <div class="text-center mb-16">
        <h3 class="text-3xl md:text-4xl font-bold mb-4">Powerful Features</h3>
        <p class="text-gray-300 text-lg max-w-2xl mx-auto">
          Everything you need to provide exceptional customer support
        </p>
      </div>

      <div class="grid md:grid-cols-3 gap-8">
        <div class="group p-8 rounded-2xl bg-white/5 backdrop-blur-lg hover:bg-white/10 transition-all duration-300 border border-white/10 hover:border-purple-400/50 hover:shadow-xl transform hover:-translate-y-1">
          <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
            💬
          </div>
          <h3 class="text-xl font-semibold mb-3">Real-time Chat</h3>
          <p class="text-gray-400 leading-relaxed">
            Instant messaging with WebSocket technology for blazing fast performance and real-time updates.
          </p>
        </div>

        <div class="group p-8 rounded-2xl bg-white/5 backdrop-blur-lg hover:bg-white/10 transition-all duration-300 border border-white/10 hover:border-purple-400/50 hover:shadow-xl transform hover:-translate-y-1">
          <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
            🏢
          </div>
          <h3 class="text-xl font-semibold mb-3">Multi-Tenant System</h3>
          <p class="text-gray-400 leading-relaxed">
            Support multiple companies with isolated data and scalable architecture for growing businesses.
          </p>
        </div>

        <div class="group p-8 rounded-2xl bg-white/5 backdrop-blur-lg hover:bg-white/10 transition-all duration-300 border border-white/10 hover:border-purple-400/50 hover:shadow-xl transform hover:-translate-y-1">
          <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-pink-500 rounded-xl flex items-center justify-center text-2xl mb-4 group-hover:scale-110 transition-transform">
            🔌
          </div>
          <h3 class="text-xl font-semibold mb-3">Easy Integration</h3>
          <p class="text-gray-400 leading-relaxed">
            Add chat to any website using a simple script with company-based customization and theming.
          </p>
        </div>
      </div>
    </section>

    <section class="relative z-10 max-w-6xl mx-auto px-6 pb-32">
      <div class="relative rounded-3xl bg-gradient-to-r from-purple-600 via-pink-600 to-purple-600 p-12 text-center shadow-2xl overflow-hidden">
        <div class="absolute inset-0 bg-black/20"></div>
        <div class="relative z-10">
          <h3 class="text-3xl md:text-4xl font-bold mb-4">Ready to get started?</h3>
          <p class="text-purple-100 text-lg mb-8 max-w-2xl mx-auto">
            Join thousands of businesses using ChatSystem to connect with their customers
          </p>

          <div class="flex flex-wrap justify-center gap-4">
            <Link
              :href="route('register')"
              class="px-8 py-3 bg-white text-purple-600 font-semibold rounded-xl hover:bg-gray-100 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
            >
              Create Free Account
            </Link>
            <Link
              :href="route('login')"
              class="px-8 py-3 border-2 border-white text-white font-semibold rounded-xl hover:bg-white/10 transition-all duration-300"
            >
              Learn More
            </Link>
          </div>
        </div>
      </div>
    </section>

    <footer class="relative z-10 text-center py-8 text-gray-400 text-sm border-t border-white/10">
      <div class="max-w-7xl mx-auto px-6">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-pink-500 rounded-lg flex items-center justify-center">
              <span class="text-sm">💬</span>
            </div>
            <span class="font-semibold">ChatSystem</span>
          </div>
          
          <div class="flex gap-6">
            <a href="#" class="hover:text-white transition-colors">Privacy</a>
            <a href="#" class="hover:text-white transition-colors">Terms</a>
            <a href="#" class="hover:text-white transition-colors">Contact</a>
          </div>
          
          <div>© {{ new Date().getFullYear() }} ChatSystem. Built with Laravel & Vue.</div>
        </div>
      </div>
    </footer>

  </div>
</template>

<style scoped>
@keyframes gradient {
  0%, 100% {
    background-position: 0% 50%;
  }
  50% {
    background-position: 100% 50%;
  }
}

.animate-gradient {
  background-size: 200% auto;
  animation: gradient 3s linear infinite;
}
</style>