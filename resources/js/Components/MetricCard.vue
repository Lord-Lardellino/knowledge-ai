<script setup lang="ts">
defineProps<{
    label:  string
    value:  string
    icon:   string
    color:  'cyan' | 'emerald' | 'amber' | 'violet'
    trend?: string
    time?:  string
}>()

// Light: colore pieno, Dark: glass con backdrop-blur
const lightBg = {
    cyan:    'bg-cyan-500 border-cyan-600',
    emerald: 'bg-emerald-500 border-emerald-600',
    amber:   'bg-amber-500 border-amber-600',
    violet:  'bg-violet-500 border-violet-600',
}
const darkGlass = {
    cyan:    'dark:bg-cyan-500/20 dark:border-cyan-400/30 dark:shadow-cyan-500/20',
    emerald: 'dark:bg-emerald-500/20 dark:border-emerald-400/30 dark:shadow-emerald-500/20',
    amber:   'dark:bg-amber-500/20 dark:border-amber-400/30 dark:shadow-amber-500/20',
    violet:  'dark:bg-violet-500/20 dark:border-violet-400/30 dark:shadow-violet-500/20',
}

// Icona: light su colore pieno = bianco semitrasparente, dark = colore tenue
const iconLight = 'bg-white/20 text-white'
const iconDark = {
    cyan:    'dark:bg-cyan-400/20 dark:text-cyan-300',
    emerald: 'dark:bg-emerald-400/20 dark:text-emerald-300',
    amber:   'dark:bg-amber-400/20 dark:text-amber-300',
    violet:  'dark:bg-violet-400/20 dark:text-violet-300',
}

// Testi: light = sempre bianco, dark = tinta colorata
const valueLight = 'text-white'
const valueDark = {
    cyan:    'dark:text-cyan-100',
    emerald: 'dark:text-emerald-100',
    amber:   'dark:text-amber-100',
    violet:  'dark:text-violet-100',
}
const muteLight = 'text-white/75'
const muteDark = {
    cyan:    'dark:text-cyan-200/60',
    emerald: 'dark:text-emerald-200/60',
    amber:   'dark:text-amber-200/60',
    violet:  'dark:text-violet-200/60',
}
</script>

<template>
    <Card
        :pt="{
            root: { class: ['backdrop-blur-md border overflow-hidden shadow-lg text-white', lightBg[color], darkGlass[color]] },
            body: { class: 'p-5' },
        }"
    >
        <template #content>
            <div class="flex items-start justify-between mb-3">
                <div
                    class="grid h-9 w-9 place-items-center rounded-lg"
                    :class="[iconLight, iconDark[color]]"
                >
                    <i :class="icon" class="text-sm" />
                </div>
                <span v-if="time" class="text-xs" :class="[muteLight, muteDark[color]]">{{ time }}</span>
            </div>
            <p class="text-sm mb-1" :class="[muteLight, muteDark[color]]">{{ label }}</p>
            <p class="text-3xl font-bold tracking-tight" :class="[valueLight, valueDark[color]]">{{ value }}</p>
            <p v-if="trend" class="text-xs mt-1" :class="[muteLight, muteDark[color]]">{{ trend }}</p>
        </template>
    </Card>
</template>
