<script setup lang="ts">
defineProps<{ quote: string; steps: { icon: string; text: string }[] }>()
</script>

<template>
    <div class="hidden lg:flex lg:w-1/2 flex-col justify-between p-12 relative overflow-hidden text-white">

        <!-- Background image + overlay -->
        <div class="absolute inset-0 bg-cover bg-center"
            style="background-image: url('https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=1200&q=80')" />
        <div class="absolute inset-0 bg-primary-900/75 dark:bg-primary-950/85" />

        <!-- Content -->
        <div class="relative z-10 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center backdrop-blur-sm">
                <i class="pi pi-bolt text-white text-sm" />
            </div>
            <span class="font-bold text-lg tracking-tight">Sapio</span>
        </div>

        <div class="relative z-10">
            <p class="text-2xl font-medium leading-snug mb-8 text-white/90">{{ quote }}</p>
            <div class="flex flex-col gap-4">
                <div v-for="step in steps" :key="step.text" class="flex items-center gap-3 text-white/75 text-sm">
                    <div class="w-7 h-7 rounded-md bg-white/15 flex items-center justify-center shrink-0 backdrop-blur-sm">
                        <i :class="step.icon" class="text-white/90 text-xs" />
                    </div>
                    {{ step.text }}
                </div>
            </div>
        </div>

        <p class="relative z-10 text-white/30 text-xs">© {{ new Date().getFullYear() }} Sapio · Knowledge AI</p>
    </div>
</template>
