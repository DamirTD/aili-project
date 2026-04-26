const DIAGNOSIS_ENDPOINT = '/api/diagnosis/analyze';

async function parseResponse(response) {
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.message || JSON.stringify(body.errors || body) || 'Request failed');
    }

    return response.json();
}

export function analyzeDiagnosis(formData) {
    return fetch(DIAGNOSIS_ENDPOINT, {
        method: 'POST',
        headers: { Accept: 'application/json' },
        body: formData,
    }).then(parseResponse);
}
