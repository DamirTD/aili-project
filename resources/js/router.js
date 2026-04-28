import { createRouter, createWebHistory } from 'vue-router';
import LandingPage from './pages/LandingPage.vue';
import AnalyzePage from './pages/AnalyzePage.vue';

const routes = [
    { path: '/', name: 'landing', component: LandingPage },
    { path: '/analyze', name: 'analyze', component: AnalyzePage },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

export default router;
