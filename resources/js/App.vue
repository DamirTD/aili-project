<script setup>
import { onMounted, ref } from 'vue';
import { analyzeDiagnosis } from './services/api';

const PROFILE_STORAGE_KEY = 'diagnosis_profile';
const SPLASH_TIMEOUT_MS = 1400;

const description = ref('');
const selectedImage = ref(null);
const previewUrl = ref('');
const result = ref(null);
const errorText = ref('');
const loading = ref(false);
const showSplash = ref(true);
const profileStep = ref('choice');
const profile = ref({
    age: '',
    gender: '',
});
const genderOptions = [
    { value: 'male', icon: '♂', label: 'Мужской' },
    { value: 'female', icon: '♀', label: 'Женский' },
];
const ageOptions = [
    { value: '0-12', icon: '👶', label: '0-12' },
    { value: '13-17', icon: '🧒', label: '13-17' },
    { value: '18-30', icon: '🧑', label: '18-30' },
    { value: '31-45', icon: '🧔', label: '31-45' },
    { value: '46-60', icon: '👨', label: '46-60' },
    { value: '61+', icon: '👴', label: '61+' },
];

function genderLabel(value) {
    const found = genderOptions.find((item) => item.value === value);
    return found ? found.label : '—';
}

function startWithProfile() {
    profileStep.value = 'profile';
}

function skipProfile() {
    clearProfile();
    profileStep.value = 'form';
}

function backToChoice() {
    profileStep.value = 'choice';
}

function backToProfile() {
    profileStep.value = 'profile';
}

function saveProfile() {
    if (!profile.value.age || !profile.value.gender) {
        clearProfile();
        profileStep.value = 'form';
        return;
    }

    if (profile.value.age.includes('-')) {
        profile.value.age = profile.value.age.split('-')[0];
    }
    if (profile.value.age === '61+') {
        profile.value.age = '61';
    }
    localStorage.setItem(PROFILE_STORAGE_KEY, JSON.stringify(profile.value));
    profileStep.value = 'form';
}

function clearProfile() {
    profile.value.age = '';
    profile.value.gender = '';
    localStorage.removeItem(PROFILE_STORAGE_KEY);
}

function buildDiagnosisFormData() {
    const formData = new FormData();
    formData.append('description', description.value.trim());

    if (profile.value.age !== '') {
        formData.append('age', profile.value.age);
    }
    if (profile.value.gender !== '') {
        formData.append('gender', profile.value.gender);
    }
    if (selectedImage.value) {
        formData.append('image', selectedImage.value);
    }

    return formData;
}

onMounted(() => {
    setTimeout(() => {
        showSplash.value = false;
    }, SPLASH_TIMEOUT_MS);

    const saved = localStorage.getItem(PROFILE_STORAGE_KEY);
    if (saved) {
        try {
            const parsed = JSON.parse(saved);
            profile.value.age = parsed.age ?? '';
            profile.value.gender = parsed.gender ?? '';
            profileStep.value = 'choice';
        } catch {
            profileStep.value = 'choice';
        }
    }
});

function onFileChange(event) {
    const file = event.target.files?.[0] ?? null;
    selectedImage.value = file;
    previewUrl.value = file ? URL.createObjectURL(file) : '';
}

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

async function onSubmit() {
    errorText.value = '';
    result.value = null;

    if (!description.value.trim() && !selectedImage.value) {
        errorText.value = 'Добавьте текст симптомов или фото.';
        return;
    }

    const formData = buildDiagnosisFormData();

    loading.value = true;

    try {
        result.value = await analyzeDiagnosis(formData);
    } catch (error) {
        errorText.value = error.message;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <main>
        <section v-if="showSplash" class="splash-screen">
            <div class="splash-logo">M</div>
            <h2>Med Assistant</h2>
            <p>Загрузка сервиса...</p>
            <div class="splash-loader"></div>
        </section>

        <section v-else class="page-shell app">
            <header class="hero">
                <span class="hero-badge">Медицинский помощник</span>
                <h1>Помощник предварительной оценки симптомов</h1>
                <p class="subtitle">
                    Опишите симптомы и/или добавьте фото. Сервис даст структурированный ответ с рисками и
                    действиями.
                </p>
                <div class="hero-points">
                    <span class="hero-point">Быстрый ответ</span>
                    <span class="hero-point">Текст + фото</span>
                    <span class="hero-point">Понятные рекомендации</span>
                </div>
            </header>

            <p v-if="errorText" class="error">{{ errorText }}</p>
            <p v-if="loading" class="loading">AI анализирует запрос...</p>

            <section v-if="profileStep === 'choice'" class="card input-card choice-card">
                <div class="choice-tiles">
                    <button type="button" class="option-tile" @click="startWithProfile">
                        <span class="tile-icon">🧑‍⚕️</span>
                        <span class="tile-title">Указать пол и возраст</span>
                    </button>
                    <button type="button" class="option-tile" @click="skipProfile">
                        <span class="tile-icon">💬</span>
                        <span class="tile-title">Сразу к форме симптомов</span>
                    </button>
                </div>
            </section>

            <section v-else-if="profileStep === 'profile'" class="card input-card">
                <h2>Профиль пациента</h2>

                <p class="pick-label">Пол</p>
                <div class="profile-tiles">
                    <button
                        v-for="item in genderOptions"
                        :key="item.value"
                        type="button"
                        class="profile-tile"
                        :class="{ active: profile.gender === item.value }"
                        @click="profile.gender = item.value"
                    >
                        <span class="tile-icon">{{ item.icon }}</span>
                        <span class="tile-title">{{ item.label }}</span>
                    </button>
                </div>

                <p class="pick-label">Возраст</p>
                <div class="profile-tiles age-tiles">
                    <button
                        v-for="item in ageOptions"
                        :key="item.value"
                        type="button"
                        class="profile-tile age-tile"
                        :class="{ active: profile.age === item.value }"
                        @click="profile.age = item.value"
                    >
                        <span class="tile-icon">{{ item.icon }}</span>
                        <span class="tile-title">{{ item.label }}</span>
                    </button>
                </div>

                <div class="choice-buttons">
                    <button type="button" class="ghost-btn" @click="backToChoice">Назад</button>
                    <button type="button" class="ghost-btn" @click="skipProfile">Пропустить</button>
                    <button type="button" class="primary-btn" @click="saveProfile">Сохранить и продолжить</button>
                </div>
            </section>

            <section v-else class="card input-card">
                <form class="grid" @submit.prevent="onSubmit">
                    <p v-if="profile.age || profile.gender" class="profile-chip">
                        Профиль: возраст {{ profile.age || '—' }}, пол {{ genderLabel(profile.gender) }}
                    </p>
                    <button
                        v-if="profile.age || profile.gender"
                        type="button"
                        class="ghost-btn clear-btn"
                        @click="clearProfile"
                    >
                        Сбросить профиль
                    </button>
                    <label>Что вы чувствуете?</label>
                    <textarea
                        v-model="description"
                        rows="5"
                        placeholder="Например: колющая боль в груди, одышка, слабость..."
                    />

                    <label>Загрузите изображение (опционально)</label>
                    <label class="upload-field">
                        <input type="file" accept="image/*" @change="onFileChange" />
                        <span class="upload-btn">{{ selectedImage ? 'Изменить файл' : 'Выбрать файл' }}</span>
                        <span class="upload-name">
                            {{ selectedImage ? selectedImage.name : 'Файл не выбран' }}
                        </span>
                    </label>

                    <img v-if="previewUrl" :src="previewUrl" alt="preview" class="preview" />

                    <div class="action-row">
                        <button type="button" class="ghost-btn" @click="backToProfile">
                            Назад к полу и возрасту
                        </button>
                        <button type="submit" class="primary-btn submit-btn">Запустить AI-анализ</button>
                    </div>
                </form>
            </section>

            <section v-if="result" class="card result">
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
                    <li v-for="step in result.care_plan" :key="step">{{ step }}</li>
                </ul>
                <p v-else class="source-empty">План действий не заполнен, рекомендуется очная консультация.</p>

                <h3>Когда срочно за помощью</h3>
                <ul v-if="result.red_flags?.length">
                    <li v-for="flag in result.red_flags" :key="flag">{{ flag }}</li>
                </ul>
                <p v-else class="source-empty">Красные флаги не указаны. При ухудшении состояния обратитесь за помощью.</p>

                <h3>Рекомендации по рискам (OWID)</h3>
                <div v-if="result.owid_insights?.length" class="owid-grid">
                    <article v-for="item in result.owid_insights" :key="item.title + item.url" class="owid-card">
                        <p class="owid-title">{{ item.title }}</p>
                        <p class="owid-label">Совет</p>
                        <p class="owid-advice">{{ item.advice || 'Следите за факторами риска и проходите профилактические проверки.' }}</p>
                        <p class="owid-label">Почему это важно</p>
                        <p class="owid-advice">{{ item.why || 'Это помогает снизить вероятность осложнений.' }}</p>
                        <p class="owid-label">Что сделать сегодня</p>
                        <p class="owid-advice">{{ item.today || 'Сделайте один конкретный шаг по самоконтролю и запишитесь к врачу при необходимости.' }}</p>
                        <a :href="item.url" target="_blank" rel="noreferrer">Подробнее в OWID</a>
                    </article>
                </div>

                <p v-if="result.image_note" class="image-note">{{ result.image_note }}</p>
                <p class="warning">{{ result.disclaimer }}</p>
            </section>
        </section>
    </main>
</template>
