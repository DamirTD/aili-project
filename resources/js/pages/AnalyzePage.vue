<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { analyzeDiagnosis } from '../services/api';

const route = useRoute();
const router = useRouter();

const mode = ref('symptoms');
const description = ref(String(route.query.d ?? sessionStorage.getItem('analysis_description') ?? ''));
const selectedMedicine = ref('');
const age = ref('');
const uploadedImage = ref(null);
const uploadedImageName = ref('');
const uploadedImagePreview = ref('');
const loading = ref(false);
const errorText = ref('');
const result = ref(null);
const medicineSearchQuery = ref('');
const medicinePage = ref(1);
const medicinesPerPage = 8;
const RESULT_STORAGE_KEY = 'analysis_result_snapshot_v1';
const RESULT_TTL_MS = 15 * 60 * 1000;

const medicineOptions = [
    { label: 'Ибупрофен', generic: 'ibuprofen' }, { label: 'Парацетамол', generic: 'acetaminophen' },
    { label: 'Аспирин', generic: 'aspirin' }, { label: 'Амоксициллин', generic: 'amoxicillin' },
    { label: 'Азитромицин', generic: 'azithromycin' }, { label: 'Метформин', generic: 'metformin' },
    { label: 'Омепразол', generic: 'omeprazole' }, { label: 'Нимесулид', generic: 'nimesulide' },
    { label: 'Кеторолак', generic: 'ketorolac' }, { label: 'Диклофенак', generic: 'diclofenac' },
    { label: 'Нурофен', generic: 'ibuprofen' }, { label: 'Ибуклин', generic: 'ibuprofen' },
    { label: 'Панадол', generic: 'acetaminophen' }, { label: 'Анальгин', generic: 'metamizole' },
    { label: 'Но-шпа', generic: 'drotaverine' }, { label: 'Лоратадин', generic: 'loratadine' },
    { label: 'Цетиризин', generic: 'cetirizine' }, { label: 'Левоцетиризин', generic: 'levocetirizine' },
    { label: 'Дезлоратадин', generic: 'desloratadine' }, { label: 'Супрастин', generic: 'chloropyramine' },
    { label: 'Амлодипин', generic: 'amlodipine' }, { label: 'Лозартан', generic: 'losartan' },
    { label: 'Эналаприл', generic: 'enalapril' }, { label: 'Бисопролол', generic: 'bisoprolol' },
    { label: 'Аторвастатин', generic: 'atorvastatin' }, { label: 'Розувастатин', generic: 'rosuvastatin' },
    { label: 'Клопидогрел', generic: 'clopidogrel' }, { label: 'Варфарин', generic: 'warfarin' },
    { label: 'Ривароксабан', generic: 'rivaroxaban' }, { label: 'Апиксабан', generic: 'apixaban' },
    { label: 'Инсулин', generic: 'insulin' }, { label: 'Сертралин', generic: 'sertraline' },
    { label: 'Эсциталопрам', generic: 'escitalopram' }, { label: 'Флуоксетин', generic: 'fluoxetine' },
    { label: 'Альпразолам', generic: 'alprazolam' }, { label: 'Амитриптилин', generic: 'amitriptyline' },
    { label: 'Левотироксин', generic: 'levothyroxine' }, { label: 'Преднизолон', generic: 'prednisone' },
    { label: 'Дексаметазон', generic: 'dexamethasone' }, { label: 'Сальбутамол', generic: 'salbutamol' },
    { label: 'Будесонид', generic: 'budesonide' }, { label: 'Амброксол', generic: 'ambroxol' },
    { label: 'Ацетилцистеин', generic: 'acetylcysteine' }, { label: 'Цефтриаксон', generic: 'ceftriaxone' },
    { label: 'Цефуроксим', generic: 'cefuroxime' }, { label: 'Ципрофлоксацин', generic: 'ciprofloxacin' },
    { label: 'Доксициклин', generic: 'doxycycline' }, { label: 'Метронидазол', generic: 'metronidazole' },
    { label: 'Флуконазол', generic: 'fluconazole' }, { label: 'Ацикловир', generic: 'acyclovir' },
    { label: 'Осельтамивир', generic: 'oseltamivir' }, { label: 'Лоперамид', generic: 'loperamide' },
    { label: 'Пенталгин', generic: 'acetaminophen' }, { label: 'Цитрамон', generic: 'aspirin' },
];

const filteredMedicineOptions = computed(() => {
    const q = medicineSearchQuery.value.trim().toLowerCase();
    if (!q) return medicineOptions;
    return medicineOptions.filter((item) => item.label.toLowerCase().includes(q) || item.generic.toLowerCase().includes(q));
});
const medicineTotalPages = computed(() => Math.max(1, Math.ceil(filteredMedicineOptions.value.length / medicinesPerPage)));
const pagedMedicineOptions = computed(() => {
    const page = Math.min(medicinePage.value, medicineTotalPages.value);
    const start = (page - 1) * medicinesPerPage;
    return filteredMedicineOptions.value.slice(start, start + medicinesPerPage);
});

function selectedMedicineMeta() {
    const normalized = String(selectedMedicine.value ?? '').trim().toLowerCase();
    if (!normalized) return null;
    return medicineOptions.find((item) => item.label.toLowerCase() === normalized || item.generic.toLowerCase() === normalized) ?? null;
}

function medicineImage(label) {
    const first = String(label ?? 'R').trim().slice(0, 1).toUpperCase() || 'R';
    const svg = `<svg xmlns='http://www.w3.org/2000/svg' width='88' height='88'><rect width='88' height='88' rx='18' fill='#eef2f9'/><rect x='27' y='15' width='34' height='11' rx='3' fill='#f8fafc' stroke='#d7dfea'/><rect x='24' y='24' width='40' height='50' rx='12' fill='#fff' stroke='#cfd7e4'/><rect x='24' y='40' width='40' height='23' rx='6' fill='#c8e3f8'/><text x='44' y='55' font-size='12' text-anchor='middle' fill='#56739d' font-family='Arial' font-weight='700'>${first}</text></svg>`;
    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}

function updateMedicineSearch(event) {
    medicineSearchQuery.value = event.target.value;
    medicinePage.value = 1;
}
function selectMedicine(item) { selectedMedicine.value = item.label; }
function clearSelectedMedicine() { selectedMedicine.value = ''; }
function prevMedicinePage() { if (medicinePage.value > 1) medicinePage.value -= 1; }
function nextMedicinePage() { if (medicinePage.value < medicineTotalPages.value) medicinePage.value += 1; }

function urgencyClass(value) {
    const text = String(value ?? '').toLowerCase();
    if (text.includes('срочно') || text.includes('высок')) return 'is-high';
    if (text.includes('сред')) return 'is-medium';
    return 'is-low';
}
function confidencePercent(value) {
    const text = String(value ?? '').toLowerCase();
    if (text.includes('высок')) return 84;
    if (text.includes('сред')) return 62;
    return 36;
}
function confidenceHint(value) {
    const text = String(value ?? '').toLowerCase();
    if (text.includes('высок')) return 'Высокое совпадение по симптомам и контексту.';
    if (text.includes('сред')) return 'Есть значимые признаки, но нужны уточнения.';
    return 'Недостаточно данных, лучше уточнить симптомы.';
}
function urgencyLabel(value) {
    const text = String(value ?? '').toLowerCase();
    if (text.includes('срочно')) return 'Нужно срочно обратиться за помощью';
    if (text.includes('высок')) return 'Высокий приоритет консультации';
    if (text.includes('сред')) return 'Желательна очная консультация';
    return 'Можно наблюдать дома по плану';
}
function isValidHttpUrl(value) { return /^https?:\/\//i.test(String(value ?? '').trim()); }
function filteredSources(items) {
    if (!Array.isArray(items)) return [];
    return items.filter((source) => isValidHttpUrl(source?.url) && String(source?.title ?? '').trim() !== '');
}
function openFdaLabelCards(items) {
    return filteredSources(items).filter((s) => String(s.title ?? '').toLowerCase().startsWith('openfda label:')).map((s) => ({
        drug: String(s.title).split(':').slice(1).join(':').trim(),
        warning: String(s.snippet ?? '').trim(),
    }));
}
function openFdaEventCards(items) {
    return filteredSources(items).filter((s) => String(s.title ?? '').toLowerCase().startsWith('openfda safety:')).map((s) => ({
        drug: String(s.title).split(':').slice(1).join(':').trim(),
        reactions: extractReactionTerms(String(s.snippet ?? '')),
    }));
}
function extractReactionTerms(snippet) {
    const marker = 'Часто встречающиеся реакции в отчетах:';
    const text = String(snippet ?? '').replace(marker, '').replace(/\.$/, '').trim();
    return text ? text.split(',').map((i) => i.trim()).filter(Boolean) : [];
}

function restoreCachedResult() {
    try {
        const raw = sessionStorage.getItem(RESULT_STORAGE_KEY);
        if (!raw) return;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return;

        const savedAt = Number(parsed.savedAt ?? 0);
        if (!savedAt || Date.now() - savedAt > RESULT_TTL_MS) {
            sessionStorage.removeItem(RESULT_STORAGE_KEY);
            return;
        }

        if (parsed.description) description.value = String(parsed.description);
        if (parsed.age !== undefined && parsed.age !== null) age.value = String(parsed.age);
        if (parsed.selectedMedicine) selectedMedicine.value = String(parsed.selectedMedicine);
        if (parsed.resultData && typeof parsed.resultData === 'object') {
            result.value = parsed.resultData;
            mode.value = 'result';
        }
    } catch {
        sessionStorage.removeItem(RESULT_STORAGE_KEY);
    }
}

function persistResultSnapshot(resultData) {
    try {
        const payload = {
            savedAt: Date.now(),
            description: description.value,
            age: age.value,
            selectedMedicine: selectedMedicine.value,
            resultData,
        };
        sessionStorage.setItem(RESULT_STORAGE_KEY, JSON.stringify(payload));
    } catch {
        // ignore storage errors
    }
}

function onImageSelected(event) {
    const file = event.target.files?.[0] ?? null;
    uploadedImage.value = file;
    uploadedImageName.value = file ? file.name : '';
    if (!file) {
        uploadedImagePreview.value = '';
        return;
    }
    if (uploadedImagePreview.value) URL.revokeObjectURL(uploadedImagePreview.value);
    uploadedImagePreview.value = URL.createObjectURL(file);
}
function clearSelectedImage() {
    uploadedImage.value = null;
    uploadedImageName.value = '';
    if (uploadedImagePreview.value) URL.revokeObjectURL(uploadedImagePreview.value);
    uploadedImagePreview.value = '';
}

function continueFromSymptoms() {
    const text = description.value.trim();
    if (!text) {
        errorText.value = 'Опишите симптомы, чтобы мы могли начать анализ.';
        return;
    }
    errorText.value = '';
    sessionStorage.setItem('analysis_description', text);
    mode.value = 'medicine';
}
function continueFromMedicine() {
    mode.value = 'age';
}
function continueFromAge() {
    if (!age.value) {
        errorText.value = 'Укажите точный возраст или нажмите "Пропустить".';
        return;
    }
    const numericAge = Number(age.value);
    if (!Number.isInteger(numericAge) || numericAge < 0 || numericAge > 120) {
        errorText.value = 'Введите корректный возраст от 0 до 120.';
        return;
    }
    errorText.value = '';
    mode.value = 'image';
}
function skipAge() {
    age.value = '';
    mode.value = 'image';
}
function composeDescriptionWithMedicine(baseDescription) {
    const text = String(baseDescription ?? '').trim();
    const med = selectedMedicineMeta();
    if (!med) return text;
    return `Лекарство: ${med.label} (${med.generic}). Что произошло после приема: ${text}`;
}

async function runAnalysis() {
    errorText.value = '';
    result.value = null;
    loading.value = true;
    mode.value = 'loading';

    const formData = new FormData();
    formData.append('description', composeDescriptionWithMedicine(description.value));
    if (age.value) formData.append('age', String(age.value));
    if (uploadedImage.value) formData.append('image', uploadedImage.value);

    try {
        result.value = await analyzeDiagnosis(formData);
        persistResultSnapshot(result.value);
        mode.value = 'result';
    } catch (error) {
        errorText.value = error.message;
        mode.value = 'image';
    } finally {
        loading.value = false;
    }
}

function goHome() {
    sessionStorage.removeItem(RESULT_STORAGE_KEY);
    sessionStorage.removeItem('analysis_description');
    router.push({ name: 'landing' });
}

function stepBack(targetMode) {
    errorText.value = '';
    mode.value = targetMode;
}

restoreCachedResult();
</script>

<template>
    <main class="page-shell app analyze-page">
        <section class="topbar analyze-topbar">
            <a href="/" class="brand-mark brand-home-link">MedAssistand AI</a>
            <button v-if="result" type="button" class="ghost-btn topbar-new-request" @click="goHome">Новый запрос</button>
        </section>
        <p v-if="errorText" class="error">{{ errorText }}</p>

        <section v-if="mode === 'symptoms'" class="card input-card">
            <h2>Шаг 1 из 4. Что вас беспокоит?</h2>
            <p class="card-note">Опишите симптомы простыми словами: когда началось, что усиливает или облегчает состояние.</p>
            <textarea v-model="description" class="prompt-textarea hero-textarea" rows="4" placeholder="Например: 2 дня держится температура 38, болит горло, слабость..." />
            <div class="choice-buttons">
                <button type="button" class="primary-btn" @click="continueFromSymptoms">Дальше</button>
            </div>
        </section>

        <section v-else-if="mode === 'medicine'" class="card input-card">
            <h2>Шаг 2 из 4. Принимали ли вы лекарство?</h2>
            <p class="card-note">Это опционально, но помогает точнее оценить возможные реакции.</p>
            <div class="medicine-picker">
                <label for="medicine-search">Лекарство (опционально)</label>
                <input id="medicine-search" :value="medicineSearchQuery" type="text" placeholder="Быстрый поиск..." @input="updateMedicineSearch" />
                <div class="medicine-preview" v-if="selectedMedicineMeta()">
                    <img :src="medicineImage(selectedMedicineMeta().label)" :alt="selectedMedicineMeta().label" />
                    <p>{{ selectedMedicineMeta().label }} ({{ selectedMedicineMeta().generic }})</p>
                    <button type="button" class="ghost-btn medicine-clear-btn" @click="clearSelectedMedicine">Сбросить</button>
                </div>
                <div class="medicine-catalog">
                    <article v-for="item in pagedMedicineOptions" :key="item.label" class="medicine-card" :class="{ active: selectedMedicineMeta() && selectedMedicineMeta().label === item.label }" @click="selectMedicine(item)">
                        <img :src="medicineImage(item.label)" :alt="item.label" />
                        <h4>{{ item.label }}</h4>
                        <p>{{ item.generic }}</p>
                    </article>
                </div>
                <div class="medicine-pagination">
                    <button type="button" class="ghost-btn" @click="prevMedicinePage" :disabled="medicinePage <= 1">←</button>
                    <span>Страница {{ medicinePage }} / {{ medicineTotalPages }}</span>
                    <button type="button" class="ghost-btn" @click="nextMedicinePage" :disabled="medicinePage >= medicineTotalPages">→</button>
                </div>
            </div>
            <div class="choice-buttons">
                <button type="button" class="ghost-btn" @click="stepBack('symptoms')">Назад</button>
                <button type="button" class="primary-btn" @click="continueFromMedicine">Дальше</button>
            </div>
        </section>

        <section v-else-if="mode === 'age'" class="card input-card result-intro">
            <h2>Шаг 3 из 4. Сколько вам лет?</h2>
            <p class="card-note">Можно указать точный возраст, это точнее, чем диапазон.</p>
            <input v-model="age" type="number" min="0" max="120" step="1" inputmode="numeric" placeholder="Например: 27" />
            <div class="choice-buttons">
                <button type="button" class="ghost-btn" @click="stepBack('medicine')">Назад</button>
                <button type="button" class="ghost-btn" @click="skipAge">Пропустить</button>
                <button type="button" class="primary-btn" @click="continueFromAge">Дальше</button>
            </div>
        </section>

        <section v-else-if="mode === 'image'" class="card input-card image-step">
            <h2>Шаг 4 из 4. Добавьте фото (по желанию)</h2>
            <p class="card-note">Например, фото высыпания, горла или другого видимого симптома.</p>
            <label class="upload-field">
                <input type="file" accept="image/*" @change="onImageSelected" />
                <span class="upload-btn">Выбрать изображение</span>
                <span class="upload-name">{{ uploadedImageName || 'Файл не выбран' }}</span>
            </label>
            <img v-if="uploadedImagePreview" :src="uploadedImagePreview" alt="Предпросмотр изображения" class="preview" />
            <div class="choice-buttons image-actions">
                <button type="button" class="ghost-btn" @click="stepBack('age')">Назад</button>
                <button type="button" class="ghost-btn" @click="clearSelectedImage">Убрать фото</button>
                <button type="button" class="primary-btn" @click="runAnalysis">Запустить анализ</button>
            </div>
        </section>

        <section v-else-if="mode === 'loading'" class="card loading-state">
            <div class="loading-ring" aria-hidden="true"></div>
            <h2>Анализируем симптомы</h2>
            <p class="loading-text">Проверяем признаки, сопоставляем данные и готовим понятный план действий.</p>
        </section>

        <section v-else-if="result" class="result-layout">
            <aside
                v-if="openFdaLabelCards(result.sources).length"
                class="card result-side result-side-left"
            >
                <p class="block-title">Результат от openFDA</p>
                <div class="openfda-grid side-openfda-grid">
                    <article class="openfda-card label-card" v-for="item in openFdaLabelCards(result.sources)" :key="`label-${item.drug}`">
                        <h4>{{ item.drug || 'Препарат' }}</h4><p>{{ item.warning }}</p>
                    </article>
                </div>
            </aside>

            <section class="card result">
                <div class="result-head">
                    <div>
                        <h2>Результат анализа</h2>
                        <p class="result-subtitle">{{ result.about }}</p>
                    </div>
                </div>

                <div class="result-grid">
                    <article class="result-card">
                        <p class="card-label">Предположение</p>
                        <h3 class="card-title">{{ result.diagnosis }}</h3>
                        <p class="card-note">Основной сценарий на основе текущих данных.</p>
                    </article>
                    <article class="result-card">
                        <p class="card-label">Уровень уверенности</p>
                        <h3 class="card-title">{{ result.confidence }}</h3>
                        <div class="confidence-track"><div class="confidence-bar" :style="{ width: `${confidencePercent(result.confidence)}%` }"></div></div>
                        <p class="confidence-caption">{{ confidenceHint(result.confidence) }}</p>
                    </article>
                    <article class="result-card" :class="urgencyClass(result.urgency)">
                        <p class="card-label">Срочность</p>
                        <h3 class="card-title">{{ result.urgency }}</h3>
                        <p class="card-text">{{ urgencyLabel(result.urgency) }}</p>
                        <p class="card-note">{{ result.home_care_window || 'Отслеживайте динамику симптомов в ближайшие сутки.' }}</p>
                    </article>
                </div>

                <section class="result-section key-insights" v-if="result.confidence_reason || result.possible_causes?.length">
                    <h3>Ключевые моменты</h3>
                    <p v-if="result.confidence_reason" class="card-text">{{ result.confidence_reason }}</p>
                    <div v-if="result.possible_causes?.length" class="reaction-chips">
                        <span v-for="item in result.possible_causes" :key="item" class="reaction-chip">{{ item }}</span>
                    </div>
                </section>

                <section class="result-section"><h3>Что делать сейчас</h3><ol class="timeline-list"><li v-for="stepItem in result.care_plan || []" :key="stepItem">{{ stepItem }}</li></ol></section>
                <section class="result-section" v-if="result.do_not_do?.length"><h3>Чего не делать</h3><ul class="clean-list warning-list"><li v-for="item in result.do_not_do" :key="item">{{ item }}</li></ul></section>
                <section class="alert-box" v-if="result.red_flags?.length"><h3>Когда обращаться срочно</h3><ul class="clean-list"><li v-for="flag in result.red_flags" :key="flag">{{ flag }}</li></ul></section>
                <p v-if="result.personalization_note" class="about">{{ result.personalization_note }}</p>
                <p class="warning">{{ result.disclaimer }}</p>
            </section>

            <aside
                v-if="openFdaEventCards(result.sources).length"
                class="card result-side result-side-right"
            >
                <p class="block-title">Результат от openFDA</p>
                <div class="openfda-grid side-openfda-grid">
                    <article class="openfda-card event-card" v-for="item in openFdaEventCards(result.sources)" :key="`event-${item.drug}`">
                        <h4>{{ item.drug || 'Препарат' }}</h4>
                        <div class="reaction-chips" v-if="item.reactions.length"><span v-for="reaction in item.reactions" :key="reaction" class="reaction-chip">{{ reaction }}</span></div>
                    </article>
                </div>
            </aside>
        </section>
    </main>
</template>
