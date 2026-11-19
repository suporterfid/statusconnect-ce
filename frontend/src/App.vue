<template>
  <div v-if="isAuthPage">
    <router-view />
  </div>
  <div v-else class="app-container">
    <AppSidebar />
    <div class="main-content">
      <AppHeader />
      <main class="content-body">
        <router-view />
      </main>
    </div>
  </div>
  <ToastContainer />
  <ConfirmDialogHost />
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppSidebar from './components/AppSidebar.vue';
import AppHeader from './components/AppHeader.vue';
import ToastContainer from './components/ToastContainer.vue';
import ConfirmDialogHost from './components/ConfirmDialogHost.vue';

const route = useRoute();
const isAuthPage = computed(() => route.name === 'login' || route.name === 'setup' || route.name === 'terms');
</script>

<style>
@import './assets/main.css';
</style>
