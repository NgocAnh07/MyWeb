<script setup lang="ts">
import { useRoute } from 'vue-router'
import Header from './components/Header.vue'
import Footer from './components/Footer.vue'

const route = useRoute()
</script>

<template>
 <Header v-if="route.name !== 'login' && !route.path.startsWith('/admin')" />

<router-view />

<Footer v-if="route.name !== 'login' && !route.path.startsWith('/admin')" />
</template>