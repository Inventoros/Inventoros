<script setup>
import { ref } from 'vue';

const isDismissed = ref(false);

const dismiss = () => {
    isDismissed.value = true;
};
</script>

<template>
    <div v-if="!isDismissed" class="mb-6 bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/40 dark:to-purple-900/40 border-2 border-blue-200 dark:border-blue-500/50 rounded-lg p-6 shadow-lg dark:shadow-xl dark:backdrop-blur-sm">
        <div class="flex items-start justify-between">
            <div class="flex items-start gap-4">
                <!-- Fun Icon -->
                <div class="flex-shrink-0">
                    <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center shadow-lg">
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Content -->
                <div class="flex-1">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2 flex items-center gap-2 flex-wrap">
                        👋 Hello, World! Welcome to Inventoros
                        <span class="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-500/30 text-blue-700 dark:text-blue-300 rounded-full border border-blue-300 dark:border-blue-500/50 font-semibold">
                            Plugin Active
                        </span>
                    </h3>
                    <p class="text-gray-700 dark:text-gray-300 mb-3 leading-relaxed">
                        This is a plugin banner! The Hello World plugin is currently active and demonstrating how plugins can inject components into existing pages.
                    </p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        You can disable this plugin from the <a href="/plugins" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 underline font-medium">Plugins page</a>.
                    </p>
                </div>
            </div>

            <!-- Dismiss Button -->
            <button
                @click="dismiss"
                class="flex-shrink-0 ml-4 p-2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-white hover:bg-gray-200 dark:hover:bg-white/10 rounded-lg transition"
                title="Dismiss this banner"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Fun Footer -->
        <div class="mt-4 pt-4 border-t border-blue-200 dark:border-blue-500/30">
            <div class="flex items-center justify-between text-xs">
                <div class="flex items-center gap-2 text-gray-600 dark:text-gray-400">
                    <svg class="w-4 h-4 text-purple-500 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    <span>Powered by Hello World Plugin v1.0.0</span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="https://github.com" class="text-gray-500 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-400 transition" title="View on GitHub">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
                        </svg>
                    </a>
                    <span class="text-gray-400 dark:text-gray-500">|</span>
                    <span class="text-green-600 dark:text-green-400 font-medium">● Active</span>
                </div>
            </div>
        </div>
    </div>
</template>
