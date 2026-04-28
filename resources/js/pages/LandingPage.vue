<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
const productSection = ref(null);
const productVideoScale = ref(0.82);
const openFaqIndex = ref(1);
const productShowcaseImage =
    "data:image/svg+xml;utf8," +
    encodeURIComponent(
        "<svg xmlns='http://www.w3.org/2000/svg' width='1600' height='900' viewBox='0 0 1600 900'>" +
            "<defs>" +
                "<linearGradient id='bg' x1='0' y1='0' x2='1' y2='1'>" +
                    "<stop offset='0%' stop-color='#f4f7ff'/>" +
                    "<stop offset='100%' stop-color='#e8f0ff'/>" +
                "</linearGradient>" +
            "</defs>" +
            "<rect width='1600' height='900' fill='url(#bg)'/>" +
            "<rect x='170' y='120' rx='24' width='1260' height='660' fill='#ffffff' stroke='#d7deef'/>" +
            "<rect x='220' y='180' rx='14' width='760' height='56' fill='#eef2fb'/>" +
            "<rect x='1000' y='180' rx='14' width='360' height='56' fill='#f4ecff'/>" +
            "<rect x='220' y='270' rx='18' width='560' height='210' fill='#f8faff' stroke='#dde5f6'/>" +
            "<rect x='800' y='270' rx='18' width='560' height='210' fill='#fff8f6' stroke='#f0ddcf'/>" +
            "<rect x='220' y='510' rx='18' width='1140' height='210' fill='#fdfdff' stroke='#e4e9f5'/>" +
            "<text x='260' y='220' font-family='Arial' font-size='28' fill='#4b5570' font-weight='700'>MedAssistant AI</text>" +
            "<text x='260' y='338' font-family='Arial' font-size='24' fill='#2e3852'>Анализ симптомов</text>" +
            "<text x='840' y='338' font-family='Arial' font-size='24' fill='#5a4458'>Результат openFDA</text>" +
        "</svg>"
    );

const faqItems = [
    {
        question: 'Это ставит диагноз?',
        answer: 'Нет. Сервис формирует предварительный разбор симптомов и план действий, но не заменяет очный прием у врача.',
    },
    {
        question: 'Что нужно заполнить перед анализом?',
        answer: 'Пошагово: описание симптомов, при необходимости лекарство, точный возраст и фото симптома (опционально).',
    },
    {
        question: 'Откуда берутся данные по лекарствам?',
        answer: 'Дополнительные карточки безопасности формируются на основе openFDA и показываются отдельно от основного ответа.',
    },
    {
        question: 'Что делать, если результат показывает высокий риск?',
        answer: 'Следуйте блоку "Когда обращаться срочно" и не откладывайте очную медицинскую помощь.',
    },
];

const infoCards = [
    {
        title: 'Пошаговый сценарий',
        description: 'Пользователь отвечает на короткие вопросы по очереди, без перегруженной формы.',
    },
    {
        title: 'Понятный результат',
        description: 'На выходе: предположение, уровень уверенности, срочность и конкретный план действий.',
    },
    {
        title: 'Безопасность по препаратам',
        description: 'Отдельно выводятся предупреждения и возможные нежелательные реакции из openFDA.',
    },
];

function goToAnalyze() {
    sessionStorage.removeItem('analysis_description');
    router.push({ name: 'analyze' });
}

function updateProductVideoScale() {
    const section = productSection.value;
    if (!section) return;
    const rect = section.getBoundingClientRect();
    const viewportHeight = window.innerHeight || 1;
    const rawProgress = (viewportHeight - rect.top) / (viewportHeight + rect.height);
    const progress = Math.min(1, Math.max(0, rawProgress));
    productVideoScale.value = 0.82 + progress * 0.18;
}

onMounted(() => {
    updateProductVideoScale();
    window.addEventListener('scroll', updateProductVideoScale, { passive: true });
    window.addEventListener('resize', updateProductVideoScale);
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', updateProductVideoScale);
    window.removeEventListener('resize', updateProductVideoScale);
});

function toggleFaq(index) {
    openFaqIndex.value = openFaqIndex.value === index ? -1 : index;
}

async function goToSection(sectionId) {
    await nextTick();
    const target = document.getElementById(sectionId);
    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>

<template>
    <main class="page-shell app">
        <header class="topbar">
            <div class="brand-mark">MedAssistant AI</div>
            <nav class="top-nav" aria-label="Main navigation">
                <button type="button" class="nav-link" @click="goToSection('product')">Product</button>
                <button type="button" class="nav-link" @click="goToSection('resources')">Как это работает</button>
                <button type="button" class="nav-link" @click="goToSection('faq')">FAQ</button>
            </nav>
        </header>

        <section class="hero">
            <h1 class="hero-title">Диагностика симптомов без догадок</h1>
            <p class="hero-subtitle">быстро, четко, по делу</p>
            <form class="hero-request" @submit.prevent="goToAnalyze">
                <button type="submit" class="hero-submit hero-cta">Попробовать бесплатно</button>
            </form>
        </section>

        <section ref="productSection" class="product-section" id="product">
            <h2 class="product-section-title">Обзор решения</h2>
            <p class="product-section-subtitle">
                Опишите состояние, выберите контекст и получите структурированный ответ с понятными шагами.
            </p>
            <div class="product-visual" aria-label="Product image showcase">
                <div class="product-video-shell" :style="{ transform: `scale(${productVideoScale})` }">
                    <img class="product-image" :src="productShowcaseImage" alt="Интерфейс MedAssistant AI" />
                </div>
            </div>
        </section>

        <section class="faq-section" id="faq">
            <h2 class="faq-title">FAQ</h2>
            <p class="faq-subtitle">Есть вопрос? У нас есть ответы.</p>
            <div class="faq-list">
                <article
                    v-for="(item, index) in faqItems"
                    :key="item.question"
                    class="faq-item"
                    :class="{ open: openFaqIndex === index }"
                >
                    <button type="button" class="faq-question" @click="toggleFaq(index)">
                        <span>{{ item.question }}</span>
                        <span class="faq-icon">{{ openFaqIndex === index ? '−' : '+' }}</span>
                    </button>
                    <p v-if="openFaqIndex === index" class="faq-answer">{{ item.answer }}</p>
                </article>
            </div>
        </section>

        <section class="resources-section" id="resources">
            <div class="resources-head">
                <h2 class="resources-title">Как это работает</h2>
            </div>
            <div class="resources-carousel resources-static-grid">
                <article v-for="item in infoCards" :key="item.title" class="resource-card">
                    <h3 class="resource-title">{{ item.title }}</h3>
                    <p class="resource-description">{{ item.description }}</p>
                </article>
            </div>
        </section>

        <footer class="page-footer">
            <p>© MedAssistant AI. Все права защищены.</p>
        </footer>
    </main>
</template>
