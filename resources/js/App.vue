<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { analyzeDiagnosis } from './services/api';

const mode = ref('landing');
const description = ref('');
const result = ref(null);
const errorText = ref('');
const loading = ref(false);
const age = ref('');

const productSection = ref(null);
const productVideoScale = ref(0.82);
const openFaqIndex = ref(1);
const resourcesCarousel = ref(null);

const ageOptions = [
    { value: '0-12', icon: '👶', label: '0-12' },
    { value: '13-17', icon: '🧒', label: '13-17' },
    { value: '18-30', icon: '🧑', label: '18-30' },
    { value: '31-45', icon: '🧔', label: '31-45' },
    { value: '46-60', icon: '👨', label: '46-60' },
    { value: '61+', icon: '👴', label: '61+' },
];

const faqItems = [
    { question: 'Нужна ли банковская карта для начала?', answer: 'Нет, можно начать без привязки карты и протестировать сервис.' },
    { question: 'Можно ли отменить подписку в любой момент?', answer: 'Да, подписку можно остановить в любой момент.' },
    { question: 'Почему стоит использовать MedAssistand AI?', answer: 'Сервис дает структурированный ответ по симптомам и понятный план действий.' },
    { question: 'Данные пациента защищены?', answer: 'Да, данные обрабатываются с учетом требований безопасности.' },
];

const resourceItems = [
    { title: 'OWID', description: 'Данные по факторам риска и метрики здоровья.', link: 'https://ourworldindata.org/' },
    { title: 'WHO', description: 'Рекомендации Всемирной организации здравоохранения.', link: 'https://www.who.int/' },
    { title: 'NHS', description: 'Проверенные гайды по симптомам.', link: 'https://www.nhs.uk/' },
    { title: 'PubMed', description: 'Научные публикации и исследования.', link: 'https://pubmed.ncbi.nlm.nih.gov/' },
];

function urgencyClass(value) {
    const text = String(value ?? '').toLowerCase();
    if (text.includes('срочно') || text.includes('высок')) {
        return 'is-high';
    }
    if (text.includes('сред')) {
        return 'is-medium';
    }
    return 'is-low';
}

function updateProductVideoScale() {
    if (!productSection.value) {
        return;
    }
    const rect = productSection.value.getBoundingClientRect();
    const viewportHeight = window.innerHeight || 1;
    const progress = (viewportHeight - rect.top) / (viewportHeight + rect.height * 0.55);
    const normalized = Math.max(0, Math.min(progress, 1));
    productVideoScale.value = 0.82 + normalized * 0.3;
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

function startFromHome() {
    if (!description.value.trim()) {
        errorText.value = 'Опишите, что вас беспокоит.';
        return;
    }
    errorText.value = '';
    result.value = null;
    age.value = '';
    mode.value = 'age';
}

async function runAnalysis() {
    errorText.value = '';
    result.value = null;
    loading.value = true;

    const formData = new FormData();
    formData.append('description', description.value.trim());
    if (age.value) {
        formData.append('age', age.value === '61+' ? '61' : age.value.split('-')[0]);
    }

    try {
        result.value = await analyzeDiagnosis(formData);
        mode.value = 'result';
    } catch (error) {
        errorText.value = error.message;
    } finally {
        loading.value = false;
    }
}

async function chooseAgeAndAnalyze(value) {
    age.value = value;
    await runAnalysis();
}

async function skipAgeAndAnalyze() {
    age.value = '';
    await runAnalysis();
}

function goHome() {
    mode.value = 'landing';
    result.value = null;
    errorText.value = '';
}

async function goToSection(sectionId) {
    if (mode.value !== 'landing') {
        goHome();
        await nextTick();
    }

    const target = document.getElementById(sectionId);
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function toggleFaq(index) {
    openFaqIndex.value = openFaqIndex.value === index ? -1 : index;
}

function scrollResources(direction) {
    if (!resourcesCarousel.value) {
        return;
    }
    const offset = Math.round(resourcesCarousel.value.clientWidth * 0.8);
    resourcesCarousel.value.scrollBy({
        left: direction === 'next' ? offset : -offset,
        behavior: 'smooth',
    });
}
</script>

<template>
    <main>
        <section class="page-shell app">
            <header class="topbar">
                <div class="brand-mark">MedAssistand AI</div>
                <nav class="top-nav" aria-label="Main navigation">
                    <button type="button" class="nav-link" @click="goToSection('product')">Product</button>
                    <button type="button" class="nav-link" @click="goToSection('resources')">Resources</button>
                    <button type="button" class="nav-link" @click="goToSection('faq')">FAQ</button>
                </nav>
            </header>

            <p v-if="errorText" class="error">{{ errorText }}</p>
            <p v-if="loading" class="loading">AI анализирует запрос...</p>

            <template v-if="mode === 'landing'">
                <section class="hero">
                    <h1 class="hero-title">Диагностика симптомов без догадок</h1>
                    <p class="hero-subtitle">быстро, четко, по делу</p>
                    <form class="hero-request" @submit.prevent="startFromHome">
                        <textarea
                            v-model="description"
                            class="prompt-textarea hero-textarea"
                            rows="3"
                            placeholder="Что вас беспокоит? Опишите симптомы..."
                        />
                        <button type="submit" class="hero-submit">Попробовать бесплатно</button>
                    </form>
                </section>

                <section ref="productSection" class="product-section" id="product">
                    <h2 class="product-section-title">Обзор решения</h2>
                    <div class="product-visual" aria-label="Product video showcase">
                        <div class="product-video-shell" :style="{ transform: `scale(${productVideoScale})` }">
                            <div class="video-placeholder">
                                <div class="video-play">▶</div>
                            </div>
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
                        <h2 class="resources-title">Resources</h2>
                        <div class="resources-controls">
                            <button type="button" class="resources-nav" @click="scrollResources('prev')">←</button>
                            <button type="button" class="resources-nav" @click="scrollResources('next')">→</button>
                        </div>
                    </div>
                    <div ref="resourcesCarousel" class="resources-carousel">
                        <article v-for="item in resourceItems" :key="item.title" class="resource-card">
                            <h3 class="resource-title">{{ item.title }}</h3>
                            <p class="resource-description">{{ item.description }}</p>
                            <a class="resource-link" :href="item.link" target="_blank" rel="noreferrer">Перейти к источнику</a>
                        </article>
                    </div>
                </section>

                <footer class="page-footer">
                    <p>© MedAssistand AI. Все права защищены.</p>
                </footer>
            </template>

            <section v-else-if="mode === 'age'" class="card input-card result-intro">
                <h2>Укажите возраст или пропустите</h2>
                <div class="profile-tiles age-tiles">
                    <button
                        v-for="item in ageOptions"
                        :key="item.value"
                        type="button"
                        class="profile-tile age-tile"
                        @click="chooseAgeAndAnalyze(item.value)"
                    >
                        <span class="tile-icon">{{ item.icon }}</span>
                        <span class="tile-title">{{ item.label }}</span>
                    </button>
                </div>
                <div class="choice-buttons">
                    <button type="button" class="ghost-btn" @click="skipAgeAndAnalyze">Пропустить</button>
                </div>
            </section>

            <section v-else-if="result" class="card result">
                <div class="result-top">
                    <h2>Результат анализа</h2>
                </div>

                <div class="metrics">
                    <article class="metric">
                        <span>Предположение</span>
                        <strong>{{ result.diagnosis }}</strong>
                    </article>
                    <article class="metric">
                        <span>Уверенность</span>
                        <strong>{{ result.confidence }}</strong>
                    </article>
                    <article class="metric" :class="urgencyClass(result.urgency)">
                        <span>Срочность</span>
                        <strong>{{ result.urgency }}</strong>
                    </article>
                </div>

                <p class="about">{{ result.about }}</p>

                <h3>Возможные причины</h3>
                <ul v-if="result.possible_causes?.length">
                    <li v-for="item in result.possible_causes" :key="item">{{ item }}</li>
                </ul>
                <p v-else class="source-empty">Причины не уточнены, ориентируйтесь на раздел срочности.</p>

                <h3>Что делать сейчас</h3>
                <ul v-if="result.care_plan?.length">
                    <li v-for="stepItem in result.care_plan" :key="stepItem">{{ stepItem }}</li>
                </ul>
                <p v-else class="source-empty">План действий не заполнен, рекомендуется очная консультация.</p>

                <h3>Когда срочно за помощью</h3>
                <ul v-if="result.red_flags?.length">
                    <li v-for="flag in result.red_flags" :key="flag">{{ flag }}</li>
                </ul>
                <p v-else class="source-empty">Красные флаги не указаны. При ухудшении состояния обратитесь за помощью.</p>

                <p class="warning">{{ result.disclaimer }}</p>
            </section>
        </section>
    </main>
</template>
